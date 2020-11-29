<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockReleaseException;
use malkusch\lock\exception\TimeoutException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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
class MemcachedMutexTest extends TestCase
{
    /**
     * @var \Memcached|MockObject
     */
    protected $memcached;

    /**
     * @var MemcachedMutex
     */
    private $mutex;

    protected function setUp(): void
    {
        $this->memcached = $this->createMock(\Memcached::class);
        $this->mutex = new MemcachedMutex('test', $this->memcached, 1);
    }

    /**
     * Tests failing to acquire the lock within the timeout.
     */
    public function testFailAcquireLock()
    {
        $this->expectException(TimeoutException::class);

        $this->memcached->expects($this->atLeastOnce())
            ->method('add')
            ->with('php_malkusch_lock:test', true, 2)
            ->willReturn(false);

        $this->mutex->synchronized(function (): void {
            $this->fail('execution is not expected');
        });
    }

    /**
     * Tests failing to release a lock.
     */
    public function testFailReleasingLock()
    {
        $this->expectException(LockReleaseException::class);

        $this->memcached->expects($this->once())
            ->method('add')
            ->with('php_malkusch_lock:test', true, 2)
            ->willReturn(true);

        $this->memcached->expects($this->once())
            ->method('delete')
            ->with('php_malkusch_lock:test')
            ->willReturn(false);

        $this->mutex->synchronized(function (): void {
        });
    }
}
