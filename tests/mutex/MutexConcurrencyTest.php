<?php

namespace malkusch\lock\Tests\mutex;

use Eloquent\Liberator\Liberator;
use malkusch\lock\mutex\FlockMutex;
use malkusch\lock\mutex\MemcachedMutex;
use malkusch\lock\mutex\Mutex;
use malkusch\lock\mutex\MySQLMutex;
use malkusch\lock\mutex\PgAdvisoryLockMutex;
use malkusch\lock\mutex\PHPRedisMutex;
use malkusch\lock\mutex\PredisMutex;
use malkusch\lock\mutex\SemaphoreMutex;
use malkusch\lock\mutex\TransactionalMutex;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Spatie\Async\Pool;

/**
 * If you want to run integration tests you should provide these environment variables:
 *
 * - MEMCACHE_HOST
 * - REDIS_URIS - a comma separated list of redis:// URIs.
 * - MYSQL_DSN, MYSQL_USER, MYSQL_PASSWORD
 * - PGSQL_DSN, PGSQL_USER, PGSQL_PASSWORD
 */
class MutexConcurrencyTest extends TestCase
{
    /** @var list<string> */
    protected static $temporaryFiles = [];
    /** @var \PDO|null the pdo instance */
    private $pdo;

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        foreach (self::$temporaryFiles as $temporaryFile) {
            unlink($temporaryFile);
        }
        self::$temporaryFiles = [];

        parent::tearDownAfterClass();
    }

    /**
     * Gets a PDO instance.
     *
     * @param string $dsn      the DSN
     * @param string $user     the user
     * @param string $password the password
     *
     * @return \PDO the PDO
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
     * @param int      $concurrency the amount of forks
     * @param callable $code        the code for the fork
     */
    private function fork(int $concurrency, callable $code): void
    {
        $pool = Pool::create();

        for ($i = 0; $i < $concurrency; ++$i) {
            $pool[] = async($code);
        }

        await($pool);
    }

    /**
     * Tests high contention empirically.
     *
     * @param callable $code         the counter code
     * @param callable $mutexFactory the mutex factory
     *
     * @dataProvider provideHighContentionCases
     *
     * @slowThreshold 1000
     */
    public function testHighContention(callable $code, callable $mutexFactory): void
    {
        $concurrency = 10;
        $iterations = 1000 / $concurrency;
        $timeout = $concurrency * 20;

        $this->fork($concurrency, static function () use ($mutexFactory, $timeout, $iterations, $code): void {
            /** @var Mutex $mutex */
            $mutex = $mutexFactory($timeout);
            for ($i = 0; $i < $iterations; ++$i) {
                $mutex->synchronized(static function () use ($code): void {
                    $code(1);
                });
            }
        });

        $counter = $code(0);
        self::assertSame($concurrency * $iterations, $counter);
    }

    /**
     * Returns test cases for testHighContention().
     *
     * @return iterable<list<mixed>>
     */
    public function provideHighContentionCases(): iterable
    {
        $cases = array_map(static function (array $mutexFactory): array {
            $filename = tempnam(sys_get_temp_dir(), 'php-lock-high-contention');

            static::$temporaryFiles[] = $filename;

            file_put_contents($filename, '0');

            return [
                static function (int $increment) use ($filename): int {
                    $counter = file_get_contents($filename);
                    $counter += $increment;

                    file_put_contents($filename, $counter);

                    return $counter;
                },
                $mutexFactory[0],
            ];
        }, static::provideExecutionIsSerializedWhenLockedCases());

        $addPDO = function ($dsn, $user, $password, $vendor) use (&$cases) {
            $pdo = $this->getPDO($dsn, $user, $password);

            $options = ['mysql' => 'engine=InnoDB'];
            $option = $options[$vendor] ?? '';
            $pdo->exec("CREATE TABLE IF NOT EXISTS counter(id INT PRIMARY KEY, counter INT) {$option}");

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
                },
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
     * @param callable $mutexFactory the Mutex factory
     *
     * @dataProvider provideExecutionIsSerializedWhenLockedCases
     *
     * @slowThreshold 2000
     */
    public function testExecutionIsSerializedWhenLocked(callable $mutexFactory): void
    {
        $time = \microtime(true);

        $this->fork(6, static function () use ($mutexFactory): void {
            /** @var Mutex $mutex */
            $mutex = $mutexFactory();
            $mutex->synchronized(static function (): void {
                \usleep(200 * 1000);
            });
        });

        $delta = \microtime(true) - $time;
        self::assertGreaterThan(1.201, $delta);
    }

    /**
     * Provides Mutex factories.
     *
     * @return callable[][] the mutex factories
     */
    public static function provideExecutionIsSerializedWhenLockedCases(): iterable
    {
        $filename = tempnam(sys_get_temp_dir(), 'mutex-concurrency-test');

        self::$temporaryFiles[] = $filename;

        $cases = [
            'flock' => [static function ($timeout = 3) use ($filename): Mutex {
                $file = fopen($filename, 'w');

                return new FlockMutex($file);
            }],

            'flockWithTimoutPcntl' => [static function ($timeout = 3) use ($filename): Mutex {
                $file = fopen($filename, 'w');
                $lock = Liberator::liberate(new FlockMutex($file, $timeout));
                $lock->strategy = FlockMutex::STRATEGY_PCNTL; // @phpstan-ignore property.notFound

                return $lock->popsValue();
            }],

            'flockWithTimoutBusy' => [static function ($timeout = 3) use ($filename): Mutex {
                $file = fopen($filename, 'w');
                $lock = Liberator::liberate(new FlockMutex($file, $timeout));
                $lock->strategy = FlockMutex::STRATEGY_BUSY; // @phpstan-ignore property.notFound

                return $lock->popsValue();
            }],

            'semaphore' => [static function ($timeout = 3) use ($filename): Mutex {
                $semaphore = sem_get(ftok($filename, 'b'));
                self::assertThat(
                    $semaphore,
                    self::logicalOr(
                        self::isInstanceOf(\SysvSemaphore::class),
                        new IsType(IsType::TYPE_RESOURCE)
                    )
                );

                return new SemaphoreMutex($semaphore);
            }],
        ];

        if (getenv('MEMCACHE_HOST')) {
            $cases['memcached'] = [static function ($timeout = 3): Mutex {
                $memcached = new \Memcached();
                $memcached->addServer(getenv('MEMCACHE_HOST'), 11211);

                return new MemcachedMutex('test', $memcached, $timeout);
            }];
        }

        $uris = getenv('REDIS_URIS') !== false ? explode(',', getenv('REDIS_URIS')) : false;

        if ($uris) {
            $cases['PredisMutex'] = [static function ($timeout = 3) use ($uris): Mutex {
                $clients = array_map(
                    static function ($uri) {
                        return new Client($uri);
                    },
                    $uris
                );

                return new PredisMutex($clients, 'test', $timeout);
            }];

            if (class_exists(\Redis::class)) {
                $cases['PHPRedisMutex'] = [
                    static function ($timeout = 3) use ($uris): Mutex {
                        /** @var \Redis[] $apis */
                        $apis = array_map(
                            static function (string $uri): \Redis {
                                $redis = new \Redis();

                                $uri = parse_url($uri);
                                $redis->connect($uri['host'], $uri['port'] ?? 6379);
                                if (!empty($uri['pass'])) {
                                    $redis->auth(
                                        empty($uri['user'])
                                        ? $uri['pass']
                                        : [$uri['user'], $uri['pass']]
                                    );
                                }

                                return $redis;
                            },
                            $uris
                        );

                        return new PHPRedisMutex($apis, 'test', $timeout);
                    },
                ];
            }
        }

        if (getenv('MYSQL_DSN')) {
            $cases['MySQLMutex'] = [static function ($timeout = 3): Mutex {
                $pdo = new \PDO(getenv('MYSQL_DSN'), getenv('MYSQL_USER'), getenv('MYSQL_USER'));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new MySQLMutex($pdo, 'test', $timeout);
            }];
        }

        if (getenv('PGSQL_DSN')) {
            $cases['PgAdvisoryLockMutex'] = [static function (): Mutex {
                $pdo = new \PDO(getenv('PGSQL_DSN'), getenv('PGSQL_USER'), getenv('PGSQL_PASSWORD'));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new PgAdvisoryLockMutex($pdo, 'test');
            }];
        }

        return $cases;
    }
}
