<?php

declare(strict_types=1);

namespace malkusch\lock\Tests\mutex;

use Eloquent\Liberator\Liberator;
use malkusch\lock\mutex\FlockMutex;
use malkusch\lock\mutex\LockMutex;
use malkusch\lock\mutex\MemcachedMutex;
use malkusch\lock\mutex\Mutex;
use malkusch\lock\mutex\MySQLMutex;
use malkusch\lock\mutex\NoMutex;
use malkusch\lock\mutex\PgAdvisoryLockMutex;
use malkusch\lock\mutex\PHPRedisMutex;
use malkusch\lock\mutex\PredisMutex;
use malkusch\lock\mutex\SemaphoreMutex;
use malkusch\lock\mutex\SpinlockMutex;
use malkusch\lock\mutex\TransactionalMutex;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * If you want to run integrations tests you should provide these environment variables:
 *
 * - MEMCACHE_HOST
 * - REDIS_URIS - a comma separated list of redis:// URIs.
 */
class MutexTest extends TestCase
{
    protected const TIMEOUT = 4;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        vfsStream::setup('test');
    }

    /**
     * Provides Mutex factories.
     *
     * @return iterable<list<mixed>>
     */
    public static function provideMutexFactoriesCases(): iterable
    {
        $cases = [
            'NoMutex' => [static function (): Mutex {
                return new NoMutex();
            }],

            'TransactionalMutex' => [static function (): Mutex {
                $pdo = new \PDO('sqlite::memory:');
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new TransactionalMutex($pdo, self::TIMEOUT);
            }],

            'FlockMutex' => [static function (): Mutex {
                $file = fopen(vfsStream::url('test/lock'), 'w');

                return new FlockMutex($file);
            }],

            'flockWithTimoutPcntl' => [static function (): Mutex {
                $file = fopen(vfsStream::url('test/lock'), 'w');
                $lock = Liberator::liberate(new FlockMutex($file, 3));
                $lock->strategy = FlockMutex::STRATEGY_PCNTL; // @phpstan-ignore property.notFound

                return $lock->popsValue();
            }],

            'flockWithTimoutBusy' => [static function ($timeout = 3): Mutex {
                $file = fopen(vfsStream::url('test/lock'), 'w');
                $lock = Liberator::liberate(new FlockMutex($file, 3));
                $lock->strategy = FlockMutex::STRATEGY_BUSY; // @phpstan-ignore property.notFound

                return $lock->popsValue();
            }],

            'SemaphoreMutex' => [static function (): Mutex {
                return new SemaphoreMutex(sem_get(ftok(__FILE__, 'a')));
            }],

            'SpinlockMutex' => [static function (): Mutex {
                $lock = new class('test') extends SpinlockMutex {
                    #[\Override]
                    protected function acquire(string $key, float $expire): bool
                    {
                        return true;
                    }

                    #[\Override]
                    protected function release(string $key): bool
                    {
                        return true;
                    }
                };

                return $lock;
            }],

            'LockMutex' => [static function (): Mutex {
                $lock = new class extends LockMutex {
                    #[\Override]
                    protected function lock(): void {}

                    #[\Override]
                    protected function unlock(): void {}
                };

                return $lock;
            }],
        ];

        if (getenv('MEMCACHE_HOST')) {
            $cases['MemcachedMutex'] = [static function (): Mutex {
                $memcache = new \Memcached();
                $memcache->addServer(getenv('MEMCACHE_HOST'), 11211);

                return new MemcachedMutex('test', $memcache, self::TIMEOUT);
            }];
        }

        if (getenv('REDIS_URIS')) {
            $uris = explode(',', getenv('REDIS_URIS'));

            $cases['PredisMutex'] = [static function () use ($uris): Mutex {
                $clients = array_map(
                    static function ($uri) {
                        return new Client($uri);
                    },
                    $uris
                );

                return new PredisMutex($clients, 'test', self::TIMEOUT);
            }];

            if (class_exists(\Redis::class)) {
                $cases['PHPRedisMutex'] = [
                    static function () use ($uris): Mutex {
                        $apis = array_map(
                            static function ($uri) {
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

                        return new PHPRedisMutex($apis, 'test', self::TIMEOUT);
                    },
                ];
            }
        }

        if (getenv('MYSQL_DSN')) {
            $cases['MySQLMutex'] = [static function (): Mutex {
                $pdo = new \PDO(getenv('MYSQL_DSN'), getenv('MYSQL_USER'), getenv('MYSQL_PASSWORD'));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new MySQLMutex($pdo, 'test' . time(), self::TIMEOUT);
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

    /**
     * Tests synchronized() executes the code and returns its result.
     *
     * @param \Closure(): Mutex $mutexFactory
     *
     * @dataProvider provideMutexFactoriesCases
     */
    #[DataProvider('provideMutexFactoriesCases')]
    public function testSynchronizedDelegates(\Closure $mutexFactory): void
    {
        /** @var Mutex $mutex */
        $mutex = $mutexFactory();
        $result = $mutex->synchronized(static function (): string {
            return 'test';
        });
        self::assertSame('test', $result);
    }

    /**
     * Tests that synchronized() released the lock.
     *
     * @param \Closure(): Mutex $mutexFactory
     *
     * @dataProvider provideMutexFactoriesCases
     */
    #[DataProvider('provideMutexFactoriesCases')]
    public function testRelease(\Closure $mutexFactory): void
    {
        $mutex = $mutexFactory();
        $mutex->synchronized(static function () {});

        $this->expectNotToPerformAssertions();
        $mutex->synchronized(static function () {});
    }

    /**
     * Tests synchronized() rethrows the exception of the code.
     *
     * @param \Closure(): Mutex $mutexFactory
     *
     * @dataProvider provideMutexFactoriesCases
     */
    #[DataProvider('provideMutexFactoriesCases')]
    public function testSynchronizedPassesExceptionThrough(\Closure $mutexFactory): void
    {
        $this->expectException(\DomainException::class);

        $mutex = $mutexFactory();
        $mutex->synchronized(static function () {
            throw new \DomainException();
        });
    }
}
