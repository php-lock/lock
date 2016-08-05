<?php

namespace malkusch\lock\mutex;

use org\bovigo\vfs\vfsStream;
use Predis\Client;
use Redis;
use Memcached;

/**
 * Tests for Mutex.
 *
 * If you want to run integrations tests you should provide these environment variables:
 *
 * - MEMCACHE_HOST
 * - REDIS_URIS - a comma separated list of redis:// URIs.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see Mutex
 */
class MutexTest extends \PHPUnit_Framework_TestCase
{

    /**
     * Provides Mutex factories.
     *
     * @return callable[][] The mutex factories.
     */
    public function provideMutexFactories()
    {
        $cases = [
            "NoMutex" => [function () {
                return new NoMutex();
            }],

            "TransactionalMutex" => [function () {
                $pdo = new \PDO("sqlite::memory:");
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                return new TransactionalMutex($pdo);
            }],

            "FlockMutex" => [function () {
                vfsStream::setup("test");
                return new FlockMutex(fopen(vfsStream::url("test/lock"), "w"));
            }],

            "SemaphoreMutex" => [function () {
                return new SemaphoreMutex(sem_get(ftok(__FILE__, "a")));
            }],

            "SpinlockMutex" => [function () {
                $mock = $this->getMockForAbstractClass(SpinlockMutex::class, ["test"]);
                $mock->expects($this->any())->method("acquire")->willReturn(true);
                $mock->expects($this->any())->method("release")->willReturn(true);
                return $mock;
            }],

            "LockMutex" => [function () {
                $mock = $this->getMockForAbstractClass(LockMutex::class);
                $mock->expects($this->any())->method("lock")->willReturn(true);
                $mock->expects($this->any())->method("unlock")->willReturn(true);
                return $mock;
            }],
        ];

        if (getenv("MEMCACHE_HOST")) {
            $cases["MemcachedMutex"] = [function () {
                $memcache = new Memcached();
                $memcache->addServer(getenv("MEMCACHE_HOST"), 11211);
                return new MemcachedMutex("test", $memcache);
            }];
        }

        if (getenv("REDIS_URIS")) {
            $uris = explode(",", getenv("REDIS_URIS"));

            $cases["PredisMutex"] = [function () use ($uris) {
                $clients = array_map(
                    function ($uri) {
                        return new Client($uri);
                    },
                    $uris
                );
                return new PredisMutex($clients, "test");
            }];

            $cases["PHPRedisMutex"] = [function () use ($uris) {
                $apis = array_map(
                    function ($uri) {
                        $redis = new Redis();
                        
                        $uri = parse_url($uri);
                        if (!empty($uri["port"])) {
                            $redis->connect($uri["host"], $uri["port"]);
                        } else {
                            $redis->connect($uri["host"]);
                        }
                        
                        return $redis;
                    },
                    $uris
                );
                return new PHPRedisMutex($apis, "test");
            }];
        }

        return $cases;
    }
    
    /**
     * Tests synchronized() executes the code and returns its result.
     *
     * @param callable $mutexFactory The Mutex factory.
     * @test
     * @dataProvider provideMutexFactories
     */
    public function testSynchronizedDelegates(callable $mutexFactory)
    {
        $mutex  = call_user_func($mutexFactory);
        $result = $mutex->synchronized(function () {
            return "test";
        });
        $this->assertEquals("test", $result);
    }
    
    /**
     * Tests that synchronized() released the lock.
     *
     * @param callable $mutexFactory The Mutex factory.
     * @test
     * @dataProvider provideMutexFactories
     */
    public function testLiveness(callable $mutexFactory)
    {
        $mutex = call_user_func($mutexFactory);
        $mutex->synchronized(function () {
        });
        $mutex->synchronized(function () {
        });
    }
    
    /**
     * Tests synchronized() rethrows the exception of the code.
     *
     * @param callable $mutexFactory The Mutex factory.
     * @test
     * @dataProvider provideMutexFactories
     * @expectedException \DomainException
     * @requires PHP 5.6
     */
    public function testSynchronizedPassesExceptionThrough(callable $mutexFactory)
    {
        $mutex = call_user_func($mutexFactory);
        $mutex->synchronized(function () {
            throw new \DomainException();
        });
    }
}
