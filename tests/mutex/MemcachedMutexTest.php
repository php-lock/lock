<?php

namespace malkusch\lock\mutex;

/**
 * Tests for MemcachedMutex.
 *
 * Please provide the environment variable MEMCACHE_HOST.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 * @requires extension memcached
 * @see MemcachedMutex
 */
class MemcachedMutexTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \Memcached
     */
    protected $memcached;

    protected function setUp()
    {
        $this->memcached = new \Memcached();
        $this->memcached->addServer(getenv("MEMCACHE_HOST") ?: "localhost", 11211);
        $this->memcached->flush();
    }

    /**
     * Tests failing to acquire the lock.
     *
     * @test
     * @expectedException \malkusch\lock\exception\TimeoutException
     */
    public function testFailAcquireLock()
    {
        $mutex = new MemcachedMutex("testFailAcquireLock", $this->memcached, 1);

        $this->memcached->add(MemcachedMutex::PREFIX."testFailAcquireLock", true, 2);

        $mutex->synchronized(function () {
            $this->fail("execution is not expected");
        });
    }
    
    /**
     * Tests failing to release a lock.
     *
     * @test
     * @expectedException \malkusch\lock\exception\LockReleaseException
     */
    public function testFailReleasingLock()
    {
        $mutex = new MemcachedMutex("testFailReleasingLock", $this->memcached, 1);
        $mutex->synchronized(function () {
            $this->memcached->delete(MemcachedMutex::PREFIX."testFailReleasingLock");
        });
    }
}
