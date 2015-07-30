<?php

namespace malkusch\lock\mutex;

use org\bovigo\vfs\vfsStream;

/**
 * Tests for Mutex.
 *
 * If you want to run memcache tests you should provide this environment variable:
 *
 * - MEMCACHE_HOST
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
            [function () {
                return new NoMutex();
            }],
            [function () {
                $pdo = new \PDO("sqlite::memory:");
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                return new TransactionalMutex($pdo);
            }],
            [function () {
                vfsStream::setup("test");
                return new FlockMutex(fopen(vfsStream::url("test/lock"), "w"));
            }],
            [function () {
                return new SemaphoreMutex(sem_get(ftok(__FILE__, "a")));
            }],
            [function () {
                $mock = $this->getMockForAbstractClass(AbstractSpinlockMutex::class, ["test"]);
                $mock->expects($this->any())->method("acquire")->willReturn(true);
                $mock->expects($this->any())->method("release")->willReturn(true);
                return $mock;
            }],
        ];
        if (getenv("MEMCACHE_HOST")) {
            $cases[] = [function () {
                $memcache = new \Memcache();
                $memcache->connect(getenv("MEMCACHE_HOST"));
                return new MemcacheMutex("test", $memcache);
            }];
            $cases[] = [function () {
                $memcache = new \Memcached();
                $memcache->addServer(getenv("MEMCACHE_HOST"), 11211);
                return new MemcachedMutex("test", $memcache);
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
