<?php

namespace malkusch\lock\mutex;

/**
 * Tests for MemcachedMutex.
 *
 * Please provide the environment variable MEMCACHE_HOST.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see MemcachedMutex
 */
class MemcachedMutexTest extends \PHPUnit_Framework_TestCase
{

    protected function setUp()
    {
        if (!getenv("MEMCACHE_HOST")) {
            $this->markTestSkipped();
            return;
        }
    }
    
    /**
     * Builds a MemcachedMutex.
     *
     * @param string $name The mutex name.
     * @param \Memcached &$memcache The memcached API.
     * @param int $timeout The timeout.
     *
     * @return MemcachedMutex The Mutex
     */
    private static function buildMemcachedMutex($name, &$memcache, $timeout = 3)
    {
        if (!getenv("MEMCACHE_HOST")) {
            return;
        }
        $memcache = new \Memcached();
        $memcache->addServer(getenv("MEMCACHE_HOST"), 11211);
        return new MemcachedMutex($name, $memcache, $timeout);
    }
    
    /**
     * Tests failing to acquire the lock.
     *
     * @test
     * @expectedException malkusch\lock\exception\TimeoutException
     */
    public function testFailAcquireLock()
    {
        $mutex = self::buildMemcachedMutex("testFailAcquireLock", $memcache, 1);
        $memcache->add(MemcachedMutex::PREFIX."testFailAcquireLock", true, 2);

        $mutex->synchronized(function () {
            $this->fail("execution is not expected");
        });
    }
    
    /**
     * Tests failing to release a lock.
     *
     * @test
     * @expectedException malkusch\lock\exception\LockReleaseException
     */
    public function testFailReleasingLock()
    {
        $mutex = self::buildMemcachedMutex("testFailReleasingLock", $memcache);

        $mutex->synchronized(function () use ($memcache) {
            $memcache->delete(MemcachedMutex::PREFIX."testFailReleasingLock");
        });
    }
}
