<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

use Eloquent\Liberator\Liberator;
use Malkusch\Lock\Mutex\FlockMutex;
use Malkusch\Lock\Mutex\MemcachedMutex;
use Malkusch\Lock\Mutex\Mutex;
use Malkusch\Lock\Mutex\MySQLMutex;
use Malkusch\Lock\Mutex\PgAdvisoryLockMutex;
use Malkusch\Lock\Mutex\PHPRedisMutex;
use Malkusch\Lock\Mutex\PredisMutex;
use Malkusch\Lock\Mutex\SemaphoreMutex;
use Malkusch\Lock\Mutex\TransactionalMutex;
use Malkusch\Lock\Util\LockUtil;
use PHPUnit\Framework\Attributes\DataProvider;
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
    /** @var \PDO|null */
    private static $pdo;

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        foreach (self::$temporaryFiles as $temporaryFile) {
            unlink($temporaryFile);
        }
        self::$temporaryFiles = [];

        self::$pdo = null;

        parent::tearDownAfterClass();
    }

    /**
     * Gets a PDO instance.
     */
    private static function getPDO(string $dsn, string $user, string $password): \PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new \PDO($dsn, $user, $password);
            self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        return self::$pdo;
    }

    /**
     * Forks, runs code in the children and wait until all finished.
     *
     * @param \Closure(): void $code The code for the fork
     */
    private function fork(int $concurrency, \Closure $code): void
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
     * @param \Closure(0|1): int     $code         The counter code
     * @param \Closure(float): Mutex $mutexFactory
     * @param \Closure(): void       $setUp
     *
     * @dataProvider provideHighContentionCases
     */
    #[DataProvider('provideHighContentionCases')]
    public function testHighContention(\Closure $code, \Closure $mutexFactory, ?\Closure $setUp = null): void
    {
        if ($setUp !== null) {
            $setUp();
        }

        $concurrency = 10;
        $iterations = 1000 / $concurrency;
        $timeout = $concurrency * 20;

        $this->fork($concurrency, static function () use ($mutexFactory, $timeout, $iterations, $code): void {
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
     * @return iterable<list<mixed>>
     */
    public static function provideHighContentionCases(): iterable
    {
        foreach (static::provideExecutionIsSerializedWhenLockedCases() as $name => [$mutexFactory]) {
            $filename = LockUtil::getInstance()->makeRandomTemporaryFilePath('test-mutex-concurrency');

            static::$temporaryFiles[] = $filename;

            yield $name => [
                static function (int $increment) use ($filename): int {
                    $counter = file_get_contents($filename);
                    $counter += $increment;

                    file_put_contents($filename, $counter);

                    return $counter;
                },
                $mutexFactory,
                static function () use ($filename): void {
                    file_put_contents($filename, '0');
                },
            ];
        }

        $makePDOCase = static function (string $dsn, string $user, string $password, string $vendor) {
            $pdo = self::getPDO($dsn, $user, $password);

            $options = ['mysql' => 'engine=InnoDB'];
            $option = $options[$vendor] ?? '';
            $pdo->exec('CREATE TABLE IF NOT EXISTS counter(id INT PRIMARY KEY, counter INT) ' . $option);

            self::$pdo = null;

            return [
                static function (int $increment) use ($dsn, $user, $password) {
                    // This prevents using a closed connection from a child.
                    if ($increment === 0) {
                        self::$pdo = null;
                    }
                    $pdo = self::getPDO($dsn, $user, $password);
                    $id = 1;
                    $select = $pdo->prepare('SELECT counter FROM counter WHERE id = ? FOR UPDATE');
                    $select->execute([$id]);
                    $counter = $select->fetchColumn();

                    $counter += $increment;

                    $pdo->prepare('UPDATE counter SET counter = ? WHERE id = ?')
                        ->execute([$counter, $id]);

                    return $counter;
                },
                static function ($timeout) use ($dsn, $user, $password) {
                    self::$pdo = null;
                    $pdo = self::getPDO($dsn, $user, $password);

                    return new TransactionalMutex($pdo, $timeout);
                },
                static function () use ($pdo): void {
                    $pdo->beginTransaction();
                    $pdo->exec('DELETE FROM counter');
                    $pdo->exec('INSERT INTO counter VALUES (1, 0)');
                    $pdo->commit();
                },
            ];
        };

        if (getenv('MYSQL_DSN')) {
            $dsn = getenv('MYSQL_DSN');
            $user = getenv('MYSQL_USER');
            $password = getenv('MYSQL_PASSWORD');
            yield 'mysql' => $makePDOCase($dsn, $user, $password, 'mysql');
        }

        if (getenv('PGSQL_DSN')) {
            $dsn = getenv('PGSQL_DSN');
            $user = getenv('PGSQL_USER');
            $password = getenv('PGSQL_PASSWORD');
            yield 'postgres' => $makePDOCase($dsn, $user, $password, 'postgres');
        }
    }

    /**
     * Tests that five processes run sequentially.
     *
     * @param \Closure(float): Mutex $mutexFactory
     *
     * @dataProvider provideExecutionIsSerializedWhenLockedCases
     */
    #[DataProvider('provideExecutionIsSerializedWhenLockedCases')]
    public function testExecutionIsSerializedWhenLocked(\Closure $mutexFactory): void
    {
        $time = \microtime(true);

        $this->fork(6, static function () use ($mutexFactory): void {
            $mutex = $mutexFactory(3);
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
     * @return iterable<list<mixed>>
     */
    public static function provideExecutionIsSerializedWhenLockedCases(): iterable
    {
        $filename = LockUtil::getInstance()->makeRandomTemporaryFilePath('test-mutex-concurrency');

        self::$temporaryFiles[] = $filename;

        yield 'flock' => [static function ($timeout) use ($filename): Mutex {
            $file = fopen($filename, 'w');

            return new FlockMutex($file, $timeout);
        }];

        yield 'flockWithTimoutPcntl' => [static function ($timeout) use ($filename): Mutex {
            $file = fopen($filename, 'w');
            $lock = Liberator::liberate(new FlockMutex($file, $timeout));
            $lock->strategy = FlockMutex::STRATEGY_PCNTL; // @phpstan-ignore property.notFound

            return $lock->popsValue();
        }];

        yield 'flockWithTimoutBusy' => [static function ($timeout) use ($filename): Mutex {
            $file = fopen($filename, 'w');
            $lock = Liberator::liberate(new FlockMutex($file, $timeout));
            $lock->strategy = FlockMutex::STRATEGY_BUSY; // @phpstan-ignore property.notFound

            return $lock->popsValue();
        }];

        yield 'semaphore' => [static function () use ($filename): Mutex {
            $semaphore = sem_get(ftok($filename, 'b'));
            self::assertThat(
                $semaphore,
                self::logicalOr(
                    self::isInstanceOf(\SysvSemaphore::class),
                    new IsType(IsType::TYPE_RESOURCE)
                )
            );

            return new SemaphoreMutex($semaphore);
        }];

        if (getenv('MEMCACHE_HOST')) {
            yield 'memcached' => [static function ($timeout): Mutex {
                $memcached = new \Memcached();
                $memcached->addServer(getenv('MEMCACHE_HOST'), 11211);

                return new MemcachedMutex('test', $memcached, $timeout);
            }];
        }

        if (getenv('REDIS_URIS')) {
            $uris = explode(',', getenv('REDIS_URIS'));

            yield 'PredisMutex' => [static function ($timeout) use ($uris): Mutex {
                $clients = array_map(
                    static fn ($uri) => new Client($uri),
                    $uris
                );

                return new PredisMutex($clients, 'test', $timeout);
            }];

            if (class_exists(\Redis::class)) {
                yield 'PHPRedisMutex' => [
                    static function ($timeout) use ($uris): Mutex {
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
            yield 'MySQLMutex' => [static function ($timeout): Mutex {
                $pdo = new \PDO(getenv('MYSQL_DSN'), getenv('MYSQL_USER'), getenv('MYSQL_PASSWORD'));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new MySQLMutex($pdo, 'test', $timeout);
            }];
        }

        if (getenv('PGSQL_DSN')) {
            yield 'PgAdvisoryLockMutex' => [static function (): Mutex {
                $pdo = new \PDO(getenv('PGSQL_DSN'), getenv('PGSQL_USER'), getenv('PGSQL_PASSWORD'));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new PgAdvisoryLockMutex($pdo, 'test');
            }];
        }
    }
}
