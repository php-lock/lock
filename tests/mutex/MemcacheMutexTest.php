<?php

namespace malkusch\lock\mutex;

/**
 * Tests for MemcacheMutex and MemcachedMutex.
 *
 * Please provide the environment variable MEMCACHE_HOST.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see MemcacheMutex
 * @see MemcachedMutex
 */
class MemcacheMutexTest extends \PHPUnit_Framework_TestCase
{

    protected function setUp()
    {
        if (!getenv("MEMCACHE_HOST")) {
            $this->markTestSkipped();
            return;
        }
    }
    
    /**
     * Builds a MemcacheMutex.
     *
     * @param string $name The mutex name.
     * @param \Memcache &$memcache The memcache API.
     * @param int $timeout The timeout.
     *
     * @return MemcacheMutex The Mutex
     */
    private function buildMemcacheMutex($name, &$memcache = null, $timeout = 3)
    {
        if (!getenv("MEMCACHE_HOST")) {
            return;

        }
        $memcache = new \Memcache();
        $memcache->connect(getenv("MEMCACHE_HOST"));
        return new MemcacheMutex($name, $memcache, $timeout);
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
    private function buildMemcachedMutex($name, &$memcache, $timeout = 3)
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
     * @param Mutex $mutex The SUT.
     * @param callable $blockKey Blocks the key.
     *
     * @test
     * @expectedException malkusch\lock\exception\TimeoutException
     * @dataProvider provideTestFailAcquireLock
     */
    public function testFailAcquireLock(Mutex $mutex, callable $blockKey)
    {
        $blockKey();
        $mutex->synchronized(function () {
            $this->fail("execution is not expected");
        });
    }
    
    /**
     * Returns test cases for testFailAcquireLock().
     *
     * @return array Test cases.
     */
    public function provideTestFailAcquireLock()
    {
        return [
            [
                $this->buildMemcacheMutex("testFailAcquireLock", $memcache, 1),
                function () use ($memcache) {
                    $memcache->add(MemcacheMutex::PREFIX."testFailAcquireLock", true, 0, 2);
                }
            ],
            [
                $this->buildMemcachedMutex("testFailAcquireLock", $memcache, 1),
                function () use ($memcache) {
                    $memcache->add(MemcachedMutex::PREFIX."testFailAcquireLock", true, 2);
                }
            ],
        ];
    }
    
    /**
     * Tests failing to release a lock.
     *
     * @param Mutex $mutex The SUT.
     * @param callable $blockRelease Blocks the release.
     *
     * @test
     * @expectedException malkusch\lock\exception\LockReleaseException
     * @dataProvider provideTestFailReleasingLock
     */
    public function testFailReleasingLock(Mutex $mutex, callable $blockRelease)
    {
        $mutex->synchronized($blockRelease);
    }
    
    /**
     * Provides test cases for testFailReleasingLock().
     *
     * @return array Test cases.
     */
    public function provideTestFailReleasingLock()
    {
        return [
            [
                $this->buildMemcacheMutex("testFailReleasingLock", $memcache),
                function () use ($memcache) {
                    $memcache->delete(MemcacheMutex::PREFIX."testFailReleasingLock");
                }
            ],
            [
                $this->buildMemcachedMutex("testFailReleasingLock", $memcache),
                function () use ($memcache) {
                    $memcache->delete(MemcachedMutex::PREFIX."testFailReleasingLock");
                }
            ],
        ];
    }
}
