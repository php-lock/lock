<?php

namespace malkusch\lock\mutex;

use Eloquent\Liberator\Liberator;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Redis;
use Spatie\Async\Pool;

/**
 * Concurrency Tests for Mutex.
 *
 * If you want to run integration tests you should provide these environment variables:
 *
 * - MEMCACHE_HOST
 * - REDIS_URIS - a comma separated list of redis:// URIs.
 * - MYSQL_DSN, MYSQL_USER, MYSQL_PASSWORD
 * - PGSQL_DSN, PGSQL_USER, PGSQL_PASSWORD
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 * @requires PHP
 * @see Mutex
 */
class MutexConcurrencyTest extends TestCase
{
    /**
     * @var array
     */
    private static $temporaryFiles = [];
    /**
     * @var \PDO The pdo instance.
     */
    private $pdo;

    public static function tearDownAfterClass(): void
    {
        foreach (self::$temporaryFiles as $temporaryFile) {
            unlink($temporaryFile);
        }

        parent::tearDownAfterClass();
    }

    /**
     * Gets a PDO instance.
     *
     * @param string $dsn The DSN.
     * @param string $user The user.
     * @param string $password The password.
     *
     * @return \PDO The PDO.
     */
    private function getPDO(string $dsn, string $user, string $password): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new \PDO($dsn, $user, $password);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        return $this->pdo;
    }

    /**
     * Forks, runs code in the children and wait until all finished.
     *
     * @param int $concurrency The amount of forks.
     * @param callable $code The code for the fork.
     */
    private function fork(int $concurrency, callable $code)
    {
        $pool = Pool::create();

        for ($i = 0; $i < $concurrency; $i++) {
            $pool[] = async($code);
        }

        await($pool);
    }

    /**
     * Tests high contention empirically.
     *
     * @param callable $code         The counter code.
     * @param callable $mutexFactory The mutex factory.
     *
     * @dataProvider provideTestHighContention
     * @slowThreshold 1000
     */
    public function testHighContention(callable $code, callable $mutexFactory)
    {
        $concurrency = 10;
        $iterations = 1000 / $concurrency;
        $timeout = $concurrency * 20;

        $this->fork($concurrency, function () use ($mutexFactory, $timeout, $iterations, $code): void {
            /** @var Mutex $mutex */
            $mutex = $mutexFactory($timeout);
            for ($i = 0; $i < $iterations; $i++) {
                $mutex->synchronized(function () use ($code): void {
                    $code(1);
                });
            }
        });

        $counter = $code(0);
        $this->assertEquals($concurrency * $iterations, $counter);
    }

    /**
     * Returns test cases for testHighContention().
     */
    public function provideTestHighContention(): array
    {
        $cases = array_map(function (array $mutexFactory): array {
            $filename = tempnam(sys_get_temp_dir(), 'php-lock-high-contention');

            static::$temporaryFiles[] = $filename;

            file_put_contents($filename, '0');

            return [
                function (int $increment) use ($filename): int {
                    $counter = file_get_contents($filename);
                    $counter += $increment;

                    file_put_contents($filename, $counter);

                    return $counter;
                },
                $mutexFactory[0]
            ];
        }, $this->provideMutexFactories());

        $addPDO = function ($dsn, $user, $password, $vendor) use (&$cases) {
            $pdo = $this->getPDO($dsn, $user, $password);

            $options = ['mysql' => 'engine=InnoDB'];
            $option = $options[$vendor] ?? '';
            $pdo->exec("CREATE TABLE IF NOT EXISTS counter(id INT PRIMARY KEY, counter INT) $option");

            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM counter');
            $pdo->exec('INSERT INTO counter VALUES (1, 0)');
            $pdo->commit();

            $this->pdo = null;

            $cases[$vendor] = [
                function ($increment) use ($dsn, $user, $password) {
                    // This prevents using a closed connection from a child.
                    if ($increment == 0) {
                        $this->pdo = null;
                    }
                    $pdo = $this->getPDO($dsn, $user, $password);
                    $id = 1;
                    $select = $pdo->prepare('SELECT counter FROM counter WHERE id = ? FOR UPDATE');
                    $select->execute([$id]);
                    $counter = $select->fetchColumn();

                    $counter += $increment;

                    $pdo->prepare('UPDATE counter SET counter = ? WHERE id = ?')
                        ->execute([$counter, $id]);

                    return $counter;
                },
                function ($timeout = 3) use ($dsn, $user, $password) {
                    $this->pdo = null;
                    $pdo = $this->getPDO($dsn, $user, $password);

                    return new TransactionalMutex($pdo, $timeout);
                }
            ];
        };

        if (getenv('MYSQL_DSN')) {
            $dsn = getenv('MYSQL_DSN');
            $user = getenv('MYSQL_USER');
            $password = getenv('MYSQL_PASSWORD');
            $addPDO($dsn, $user, $password, 'mysql');
        }

        if (getenv('PGSQL_DSN')) {
            $dsn = getenv('PGSQL_DSN');
            $user = getenv('PGSQL_USER');
            $password = getenv('PGSQL_PASSWORD');
            $addPDO($dsn, $user, $password, 'postgres');
        }

        return $cases;
    }

    /**
     * Tests that five processes run sequentially.
     *
     * @param callable $mutexFactory The Mutex factory.
     * @dataProvider provideMutexFactories
     * @slowThreshold 2000
     */
    public function testExecutionIsSerializedWhenLocked(callable $mutexFactory)
    {
        $timestamp = hrtime(true);

        $this->fork(5, function () use ($mutexFactory): void {
            /** @var Mutex $mutex */
            $mutex = $mutexFactory();
            $mutex->synchronized(function (): void {
                \usleep(200000);
            });
        });

        $delta = \hrtime(true) - $timestamp;
        $this->assertGreaterThan(1e9, $delta);
    }

    /**
     * Provides Mutex factories.
     *
     * @return callable[][] The mutex factories.
     */
    public function provideMutexFactories()
    {
        $filename = tempnam(sys_get_temp_dir(), 'mutex-concurrency-test');

        self::$temporaryFiles[] = $filename;

        $cases = [
            'flock' => [function ($timeout = 3) use ($filename): Mutex {
                $file = fopen($filename, 'w');

                return new FlockMutex($file);
            }],

            'flockWithTimoutPcntl' => [function ($timeout = 3) use ($filename): Mutex {
                $file = fopen($filename, 'w');
                $lock = Liberator::liberate(new FlockMutex($file, $timeout));
                $lock->stategy = FlockMutex::STRATEGY_PCNTL;

                return $lock->popsValue();
            }],

            'flockWithTimoutBusy' => [function ($timeout = 3) use ($filename): Mutex {
                $file = fopen($filename, 'w');
                $lock = Liberator::liberate(new FlockMutex($file, $timeout));
                $lock->stategy = FlockMutex::STRATEGY_BUSY;

                return $lock->popsValue();
            }],

            'semaphore' => [function ($timeout = 3) use ($filename): Mutex {
                $semaphore = sem_get(ftok($filename, 'b'));
                $this->assertTrue(is_resource($semaphore));

                return new SemaphoreMutex($semaphore);
            }],
        ];

        if (getenv('MEMCACHE_HOST')) {
            $cases['memcached'] = [function ($timeout = 3): Mutex {
                $memcached = new \Memcached();
                $memcached->addServer(getenv('MEMCACHE_HOST'), 11211);

                return new MemcachedMutex('test', $memcached, $timeout);
            }];
        }

        $uris = getenv('REDIS_URIS') !== false ? explode(',', getenv('REDIS_URIS')) : false;

        if ($uris) {
            $cases['PredisMutex'] = [function ($timeout = 3) use ($uris): Mutex {
                $clients = array_map(
                    function ($uri) {
                        return new Client($uri);
                    },
                    $uris
                );

                return new PredisMutex($clients, 'test', $timeout);
            }];

            if (class_exists(Redis::class)) {
                $cases['PHPRedisMutex'] = [
                    function ($timeout = 3) use ($uris): Mutex {
                        /** @var Redis[] $apis */
                        $apis = array_map(
                            function (string $uri): Redis {
                                $redis = new Redis();

                                $uri = parse_url($uri);
                                if (!empty($uri['port'])) {
                                    $redis->connect($uri['host'], $uri['port']);
                                } else {
                                    $redis->connect($uri['host']);
                                }

                                return $redis;
                            },
                            $uris
                        );

                        return new PHPRedisMutex($apis, 'test', $timeout);
                    }
                ];
            }
        }

        if (getenv('MYSQL_DSN')) {
            $cases['MySQLMutex'] = [function ($timeout = 3): Mutex {
                $pdo = new \PDO(getenv('MYSQL_DSN'), getenv('MYSQL_USER'), getenv('MYSQL_USER'));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new MySQLMutex($pdo, 'test', $timeout);
            }];
        }

        if (getenv('PGSQL_DSN')) {
            $cases['PgAdvisoryLockMutex'] = [function (): Mutex {
                $pdo = new \PDO(getenv('PGSQL_DSN'), getenv('PGSQL_USER'), getenv('PGSQL_PASSWORD'));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new PgAdvisoryLockMutex($pdo, 'test');
            }];
        }

        return $cases;
    }
}
