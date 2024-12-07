<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

use Eloquent\Liberator\Liberator;
use Malkusch\Lock\Mutex\AbstractLockMutex;
use Malkusch\Lock\Mutex\AbstractSpinlockMutex;
use Malkusch\Lock\Mutex\FlockMutex;
use Malkusch\Lock\Mutex\MemcachedMutex;
use Malkusch\Lock\Mutex\Mutex;
use Malkusch\Lock\Mutex\MySQLMutex;
use Malkusch\Lock\Mutex\NoMutex;
use Malkusch\Lock\Mutex\PostgreSQLMutex;
use Malkusch\Lock\Mutex\RedisMutex;
use Malkusch\Lock\Mutex\SemaphoreMutex;
use Malkusch\Lock\Mutex\TransactionalMutex;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
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
        parent::setUpBeforeClass();

        vfsStream::setup('test');
    }

    /**
     * Provides Mutex factories.
     *
     * @return iterable<list<mixed>>
     */
    public static function provideMutexFactoriesCases(): iterable
    {
        yield 'NoMutex' => [static function (): Mutex {
            return new NoMutex();
        }];

        yield 'TransactionalMutex' => [static function (): Mutex {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            return new TransactionalMutex($pdo, self::TIMEOUT);
        }];

        yield 'FlockMutex' => [static function (): Mutex {
            $file = fopen(vfsStream::url('test/lock'), 'w');

            return new FlockMutex($file);
        }];

        yield 'flockWithTimoutPcntl' => [static function (): Mutex {
            $file = fopen(vfsStream::url('test/lock'), 'w');
            $lock = Liberator::liberate(new FlockMutex($file, 3));
            $lock->strategy = FlockMutex::STRATEGY_PCNTL; // @phpstan-ignore property.notFound

            return $lock->popsValue();
        }];

        yield 'flockWithTimoutBusy' => [static function (): Mutex {
            $file = fopen(vfsStream::url('test/lock'), 'w');
            $lock = Liberator::liberate(new FlockMutex($file, 3));
            $lock->strategy = FlockMutex::STRATEGY_BUSY; // @phpstan-ignore property.notFound

            return $lock->popsValue();
        }];

        if (extension_loaded('sysvsem')) {
            yield 'SemaphoreMutex' => [static function (): Mutex {
                return new SemaphoreMutex(sem_get(ftok(__FILE__, 'a')));
            }];
        }

        yield 'AbstractSpinlockMutex' => [static function (): Mutex {
            $lock = new class('test') extends AbstractSpinlockMutex {
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
        }];

        yield 'AbstractLockMutex' => [static function (): Mutex {
            $lock = new class extends AbstractLockMutex {
                #[\Override]
                protected function lock(): void {}

                #[\Override]
                protected function unlock(): void {}
            };

            return $lock;
        }];

        if (getenv('MEMCACHE_HOST')) {
            yield 'MemcachedMutex' => [static function (): Mutex {
                $memcache = new \Memcached();
                $memcache->addServer(getenv('MEMCACHE_HOST'), 11211);

                return new MemcachedMutex('test', $memcache, self::TIMEOUT);
            }];
        }

        if (getenv('REDIS_URIS')) {
            $uris = explode(',', getenv('REDIS_URIS'));

            yield 'PredisMutex' => [static function () use ($uris): Mutex {
                $clients = array_map(
                    static fn ($uri) => new Client($uri),
                    $uris
                );

                return new RedisMutex($clients, 'test', self::TIMEOUT);
            }];

            if (class_exists(\Redis::class)) {
                yield 'PHPRedisMutex' => [
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

                        return new RedisMutex($apis, 'test', self::TIMEOUT);
                    },
                ];
            }
        }

        if (getenv('MYSQL_DSN')) {
            yield 'MySQLMutex' => [static function (): Mutex {
                $pdo = new \PDO(getenv('MYSQL_DSN'), getenv('MYSQL_USER'), getenv('MYSQL_PASSWORD'));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new MySQLMutex($pdo, 'test', self::TIMEOUT);
            }];
        }

        if (getenv('PGSQL_DSN')) {
            yield 'PostgreSQLMutex' => [static function (): Mutex {
                $pdo = new \PDO(getenv('PGSQL_DSN'), getenv('PGSQL_USER'), getenv('PGSQL_PASSWORD'));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new PostgreSQLMutex($pdo, 'test');
            }];
        }
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
     * @doesNotPerformAssertions
     *
     * @dataProvider provideMutexFactoriesCases
     */
    #[DoesNotPerformAssertions]
    #[DataProvider('provideMutexFactoriesCases')]
    public function testRelease(\Closure $mutexFactory): void
    {
        $mutex = $mutexFactory();
        $mutex->synchronized(static function () {});

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
