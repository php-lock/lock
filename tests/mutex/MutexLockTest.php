<?php

namespace malkusch\lock\mutex;

/**
 * Tests for locking in Mutex.
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
class MutexLockTest extends \PHPUnit_Framework_TestCase
{
    
    /**
     * Provides Mutex factories.
     *
     * @return callable[][] The mutex factories.
     */
    public function provideMutexFactories()
    {
        $lockFile = stream_get_meta_data(tmpfile())["uri"];
        $cases = [
            [function () use ($lockFile) {
                return new FlockMutex(fopen($lockFile, "w"));
            }],
            [function () {
                return new SemaphoreMutex(sem_get(ftok(__FILE__, "b")));
            }],
        ];
        if (getenv("MEMCACHE_HOST")) {
            $cases[] = [function () {
                $memcache = new \Memcache();
                $memcache->connect(getenv("MEMCACHE_HOST"));
                return new MemcacheMutex("test", $memcache);
            }];
            $cases[] = [function () {
                $memcached = new \Memcached();
                $memcached->addServer(getenv("MEMCACHE_HOST"), 11211);
                return new MemcachedMutex("test", $memcached);
            }];
        }
        return $cases;
    }
    
    /**
     * Tests that two processes run sequentially.
     *
     * @param callable $mutexFactory The Mutex factory.
     * @test
     * @dataProvider provideMutexFactories
     */
    public function testSerialisation(callable $mutexFactory)
    {
        $timestamp = microtime(true);
        $isChild   = pcntl_fork() == 0;
        
        $mutex = call_user_func($mutexFactory);
        $mutex->synchronized(function () {
            usleep(500000);
        });
        
        // exit the child.
        $isChild ? exit() : pcntl_wait($status);

        $delta = microtime(true) - $timestamp;
        $this->assertGreaterThan(1, $delta);
    }
}
