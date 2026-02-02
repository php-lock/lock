<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

require_once __DIR__ . '/../TestAccess.php';
use Malkusch\Lock\Mutex\DistributedMutex;
use Malkusch\Lock\Mutex\FlockMutex;
use Malkusch\Lock\Mutex\MemcachedMutex;
use Malkusch\Lock\Mutex\Mutex;
use Malkusch\Lock\Mutex\MySQLMutex;
use Malkusch\Lock\Mutex\PostgreSQLMutex;
use Malkusch\Lock\Mutex\RedisMutex;
use Malkusch\Lock\Mutex\SemaphoreMutex;
use Malkusch\Lock\Util\LockUtil;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\NativeType;
use PHPUnit\Framework\TestCase;
use Predis\Client as PredisClient;
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

        $this->fork($concurrency, static function () use ($mutexFactory, $timeout, $iterations, $code) {
            $mutex = $mutexFactory($timeout);
            for ($i = 0; $i < $iterations; ++$i) {
                $mutex->synchronized(static function () use ($code) {
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
                static function (int $increment) use ($filename) {
                    $counter = file_get_contents($filename);
                    $counter += $increment;

                    file_put_contents($filename, $counter);

                    return $counter;
                },
                $mutexFactory,
                static function () use ($filename) {
                    file_put_contents($filename, '0');
                },
            ];
        }
    }

    /**
     * Tests that five processes run sequentially.
     *
     * @param \Closure(float): Mutex $mutexFactory
     */
    #[DataProvider('provideExecutionIsSerializedWhenLockedCases')]
    public function testExecutionIsSerializedWhenLocked(\Closure $mutexFactory): void
    {
        $time = \microtime(true);

        $this->fork(6, static function () use ($mutexFactory) {
            $mutex = $mutexFactory(3);
            $mutex->synchronized(static function () {
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

        yield 'flock' => [static function ($timeout) use ($filename) {
            $file = fopen($filename, 'w');

            return new FlockMutex($file, $timeout);
        }];

        if (extension_loaded('pcntl')) {
            yield 'flockWithTimoutPcntl' => [static function ($timeout) use ($filename) {
                $file = fopen($filename, 'w');
                $lock = new FlockMutex($file, $timeout);
                (new \Malkusch\Lock\Tests\TestAccess($lock))->setProperty('strategy', \Closure::bind(static fn () => FlockMutex::STRATEGY_PCNTL, null, FlockMutex::class)());

                return (new \Malkusch\Lock\Tests\TestAccess($lock))->popsValue();

            }];
        }

        yield 'flockWithTimoutLoop' => [static function ($timeout) use ($filename) {
            $file = fopen($filename, 'w');
            $lock = new FlockMutex($file, $timeout);
            (new \Malkusch\Lock\Tests\TestAccess($lock))->setProperty('strategy', \Closure::bind(static fn () => FlockMutex::STRATEGY_LOOP, null, FlockMutex::class)());

            return (new \Malkusch\Lock\Tests\TestAccess($lock))->popsValue();
        }];

        if (extension_loaded('sysvsem')) {
            yield 'semaphore' => [static function () use ($filename) {
                $semaphore = sem_get(ftok($filename, 'b'));
                self::assertThat(
                    $semaphore,
                    self::logicalOr(
                        self::isInstanceOf(\SysvSemaphore::class),
                        new IsType(NativeType::Resource)
                    )
                );

                return new SemaphoreMutex($semaphore);
            }];
        }

        if (getenv('MEMCACHE_HOST')) {
            yield 'memcached' => [static function ($timeout) {
                $memcached = new \Memcached();
                $memcached->addServer(getenv('MEMCACHE_HOST'), 11211);

                return new MemcachedMutex('test', $memcached, $timeout);
            }];
        }

        if (getenv('REDIS_URIS')) {
            $uris = explode(',', getenv('REDIS_URIS'));

            yield 'DistributedMutex RedisMutex /w Predis' => [static function ($timeout) use ($uris) {
                $clients = array_map(
                    static fn ($uri) => new PredisClient($uri),
                    $uris
                );

                $mutexes = array_map(
                    static fn ($client) => new RedisMutex($client, 'test', $timeout),
                    $clients
                );

                return new DistributedMutex($mutexes, $timeout);
            }];

            if (class_exists(\Redis::class)) {
                yield 'DistributedMutex RedisMutex /w PHPRedis' => [
                    static function ($timeout) use ($uris) {
                        $clients = array_map(
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

                        $mutexes = array_map(
                            static fn ($client) => new RedisMutex($client, 'test', $timeout),
                            $clients
                        );

                        return new DistributedMutex($mutexes, $timeout);
                    },
                ];
            }
        }

        if (getenv('MYSQL_DSN')) {
            yield 'MySQLMutex' => [static function ($timeout) {
                $pdo = new \PDO(getenv('MYSQL_DSN'), getenv('MYSQL_USER'), getenv('MYSQL_PASSWORD'));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new MySQLMutex($pdo, 'test', $timeout);
            }];
        }

        if (getenv('PGSQL_DSN')) {
            yield 'PostgreSQLMutex' => [static function () {
                $pdo = new \PDO(getenv('PGSQL_DSN'), getenv('PGSQL_USER'), getenv('PGSQL_PASSWORD'));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new PostgreSQLMutex($pdo, 'test');
            }];
        }
    }
}
