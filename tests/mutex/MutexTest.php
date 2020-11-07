<?php

namespace malkusch\lock\mutex;

use Eloquent\Liberator\Liberator;
use Memcached;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Redis;

/**
 * Tests for Mutex.
 *
 * If you want to run integrations tests you should provide these environment variables:
 *
 * - MEMCACHE_HOST
 * - REDIS_URIS - a comma separated list of redis:// URIs.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 * @see Mutex
 */
class MutexTest extends TestCase
{
    const TIMEOUT = 4;

    public static function setUpBeforeClass(): void
    {
        vfsStream::setup('test');
    }

    /**
     * Provides Mutex factories.
     *
     * @return callable[][] The mutex factories.
     */
    public function provideMutexFactories()
    {
        $cases = [
            'NoMutex' => [function (): Mutex {
                return new NoMutex();
            }],

            'TransactionalMutex' => [function (): Mutex {
                $pdo = new \PDO('sqlite::memory:');
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new TransactionalMutex($pdo, self::TIMEOUT);
            }],

            'FlockMutex' => [function (): Mutex {
                $file = fopen(vfsStream::url('test/lock'), 'w');

                return new FlockMutex($file);
            }],

            'flockWithTimoutPcntl' => [function (): Mutex {
                $file = fopen(vfsStream::url('test/lock'), 'w');
                $lock = Liberator::liberate(new FlockMutex($file, 3));
                $lock->stategy = FlockMutex::STRATEGY_PCNTL;

                return $lock->popsValue();
            }],

            'flockWithTimoutBusy' => [function ($timeout = 3): Mutex {
                $file = fopen(vfsStream::url('test/lock'), 'w');
                $lock = Liberator::liberate(new FlockMutex($file, 3));
                $lock->stategy = FlockMutex::STRATEGY_BUSY;

                return $lock->popsValue();
            }],

            'SemaphoreMutex' => [function (): Mutex {
                return new SemaphoreMutex(sem_get(ftok(__FILE__, 'a')));
            }],

            'SpinlockMutex' => [function (): Mutex {
                $mock = $this->getMockForAbstractClass(SpinlockMutex::class, ['test']);
                $mock->expects($this->atLeastOnce())
                    ->method('acquire')
                    ->willReturn(true);

                $mock->expects($this->atLeastOnce())
                    ->method('release')
                    ->willReturn(true);

                return $mock;
            }],

            'LockMutex' => [function (): Mutex {
                $mock = $this->getMockForAbstractClass(LockMutex::class);
                $mock->expects($this->atLeastOnce())
                    ->method('lock');

                $mock->expects($this->atLeastOnce())
                    ->method('unlock');

                return $mock;
            }],
        ];

        if (getenv('MEMCACHE_HOST')) {
            $cases['MemcachedMutex'] = [function (): Mutex {
                $memcache = new Memcached();
                $memcache->addServer(getenv('MEMCACHE_HOST'), 11211);

                return new MemcachedMutex('test', $memcache, self::TIMEOUT);
            }];
        }

        if (getenv('REDIS_URIS')) {
            $uris = explode(',', getenv('REDIS_URIS'));

            $cases['PredisMutex'] = [function () use ($uris): Mutex {
                $clients = array_map(
                    function ($uri) {
                        return new Client($uri);
                    },
                    $uris
                );

                return new PredisMutex($clients, 'test', self::TIMEOUT);
            }];

            if (class_exists(Redis::class)) {
                $cases['PHPRedisMutex'] = [
                    function () use ($uris): Mutex {
                        /** @var Redis[] $apis */
                        $apis = array_map(
                            function ($uri) {
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

                        return new PHPRedisMutex($apis, 'test', self::TIMEOUT);
                    }
                ];
            }
        }

        if (getenv('MYSQL_DSN')) {
            $cases['MySQLMutex'] = [function (): Mutex {
                $pdo = new \PDO(getenv('MYSQL_DSN'), getenv('MYSQL_USER'));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new MySQLMutex($pdo, 'test' . time(), self::TIMEOUT);
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

    /**
     * Tests synchronized() executes the code and returns its result.
     *
     * @param callable $mutexFactory The Mutex factory.
     * @dataProvider provideMutexFactories
     */
    public function testSynchronizedDelegates(callable $mutexFactory)
    {
        /** @var Mutex $mutex */
        $mutex = $mutexFactory();
        $result = $mutex->synchronized(function (): string {
            return 'test';
        });
        $this->assertSame('test', $result);
    }

    /**
     * Tests that synchronized() released the lock.
     *
     * @param callable $mutexFactory The Mutex factory.
     * @dataProvider provideMutexFactories
     */
    public function testRelease(callable $mutexFactory)
    {
        $mutex = call_user_func($mutexFactory);
        $mutex->synchronized(function () {
        });

        $this->expectNotToPerformAssertions();
        $mutex->synchronized(function () {
        });
    }

    /**
     * Tests synchronized() rethrows the exception of the code.
     *
     * @param callable $mutexFactory The Mutex factory.
     * @dataProvider provideMutexFactories
     */
    public function testSynchronizedPassesExceptionThrough(callable $mutexFactory)
    {
        $this->expectException(\DomainException::class);

        /** @var Mutex $mutex */
        $mutex = $mutexFactory();
        $mutex->synchronized(function () {
            throw new \DomainException();
        });
    }
}
