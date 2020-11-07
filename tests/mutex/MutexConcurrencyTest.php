<?php

namespace malkusch\lock\mutex;

use Eloquent\Liberator\Liberator;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Redis;
use Spork\ProcessManager;

/**
 * Concurrency Tests for Mutex.
 *
 * If you want to run integration tests you should provide these environment variables:
 *
 * - MEMCACHE_HOST
 * - REDIS_URIS - a comma separated list of redis:// URIs.
 * - MYSQL_DSN, MYSQL_USER
 * - PGSQL_DSN, PGSQL_USER
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
     * @var \PDO The pdo instance.
     */
    private $pdo;

    /**
     * @var string
     */
    private $path;

    protected function tearDown(): void
    {
        if ($this->path) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    /**
     * Gets a PDO instance.
     *
     * @param string $dsn The DSN.
     * @param string $user The user.
     *
     * @return \PDO The PDO.
     */
    private function getPDO(string $dsn, string $user): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new \PDO($dsn, $user);
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
    private function fork($concurrency, callable $code)
    {
        $manager = new ProcessManager();
        $manager->setDebug(true);

        for ($i = 0; $i < $concurrency; $i++) {
            $manager->fork($code);
        }

        $manager->check();
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
        $concurrency = 2;
        $iterations = 20000 / $concurrency;
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
     *
     * @return array The test cases.
     */
    public function provideTestHighContention()
    {
        $cases = array_map(function (array $mutexFactory) {
            $file = tmpfile();
            $this->assertEquals(4, fwrite($file, pack('i', 0)), 'Expected 4 bytes to be written to temporary file.');

            return [
                function (int $increment) use ($file): int {
                    rewind($file);
                    flock($file, LOCK_EX);
                    $data = fread($file, 4);

                    $this->assertEquals(4, strlen($data), 'Expected four bytes to be present in temporary file.');

                    $counter = unpack('i', $data)[1];

                    $counter += $increment;

                    rewind($file);
                    fwrite($file, pack('i', $counter));

                    flock($file, LOCK_UN);

                    return $counter;
                },
                $mutexFactory[0]
            ];
        }, $this->provideMutexFactories());

        $addPDO = function ($dsn, $user, $vendor) use (&$cases) {
            $pdo = $this->getPDO($dsn, $user);

            $options = ['mysql' => 'engine=InnoDB'];
            $option = $options[$vendor] ?? '';
            $pdo->exec("CREATE TABLE IF NOT EXISTS counter(id INT PRIMARY KEY, counter INT) $option");

            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM counter');
            $pdo->exec('INSERT INTO counter VALUES (1, 0)');
            $pdo->commit();

            $this->pdo = null;

            $cases[$vendor] = [
                function ($increment) use ($dsn, $user) {
                    // This prevents using a closed connection from a child.
                    if ($increment == 0) {
                        $this->pdo = null;
                    }
                    $pdo = $this->getPDO($dsn, $user);
                    $id = 1;
                    $select = $pdo->prepare('SELECT counter FROM counter WHERE id = ? FOR UPDATE');
                    $select->execute([$id]);
                    $counter = $select->fetchColumn();

                    $counter += $increment;

                    $pdo->prepare('UPDATE counter SET counter = ? WHERE id = ?')
                        ->execute([$counter, $id]);

                    return $counter;
                },
                function ($timeout = 3) use ($dsn, $user) {
                    $this->pdo = null;
                    $pdo = $this->getPDO($dsn, $user);

                    return new TransactionalMutex($pdo, $timeout);
                }
            ];
        };

        if (getenv('MYSQL_DSN')) {
            $dsn = getenv('MYSQL_DSN');
            $user = getenv('MYSQL_USER');
            $addPDO($dsn, $user, 'mysql');
        }

        if (getenv('PGSQL_DSN')) {
            $dsn = getenv('PGSQL_DSN');
            $user = getenv('PGSQL_USER');
            $addPDO($dsn, $user, 'postgres');
        }

        return $cases;
    }

    /**
     * Tests that two processes run sequentially.
     *
     * @param callable $mutexFactory The Mutex factory.
     * @dataProvider provideMutexFactories
     * @slowThreshold 1000
     */
    public function testExecutionIsSerializedWhenLocked(callable $mutexFactory)
    {
        $timestamp = microtime(true);

        $this->fork(2, function () use ($mutexFactory): void {
            /** @var Mutex $mutex */
            $mutex = $mutexFactory();
            $mutex->synchronized(function (): void {
                usleep(500000);
            });
        });

        $delta = microtime(true) - $timestamp;
        $this->assertGreaterThan(1, $delta);
    }

    /**
     * Provides Mutex factories.
     *
     * @return callable[][] The mutex factories.
     */
    public function provideMutexFactories()
    {
        $this->path = tempnam(sys_get_temp_dir(), 'mutex-concurrency-test');

        $cases = [
            'flock' => [function ($timeout = 3): Mutex {
                $file = fopen($this->path, 'w');

                return new FlockMutex($file);
            }],

            'flockWithTimoutPcntl' => [function ($timeout = 3): Mutex {
                $file = fopen($this->path, 'w');
                $lock = Liberator::liberate(new FlockMutex($file, $timeout));
                $lock->stategy = FlockMutex::STRATEGY_PCNTL;

                return $lock->popsValue();
            }],

            'flockWithTimoutBusy' => [function ($timeout = 3): Mutex {
                $file = fopen($this->path, 'w');
                $lock = Liberator::liberate(new FlockMutex($file, $timeout));
                $lock->stategy = FlockMutex::STRATEGY_BUSY;

                return $lock->popsValue();
            }],

            'semaphore' => [function ($timeout = 3): Mutex {
                $semaphore = sem_get(ftok($this->path, 'b'));
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

        $uris = getenv('REDIS_URIS') !== false ? explode(',', getenv('REDIS_URIS')) : ['redis://localhost:6379'];

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

        if (getenv('MYSQL_DSN')) {
            $cases['MySQLMutex'] = [function ($timeout = 3): Mutex {
                $pdo = new \PDO(getenv('MYSQL_DSN'), getenv('MYSQL_USER'));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new MySQLMutex($pdo, 'test', $timeout);
            }];
        }

        if (getenv('PGSQL_DSN')) {
            $cases['PgAdvisoryLockMutex'] = [function (): Mutex {
                $pdo = new \PDO(getenv('PGSQL_DSN'), getenv('PGSQL_USER'));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new PgAdvisoryLockMutex($pdo, 'test');
            }];
        }

        return $cases;
    }
}
