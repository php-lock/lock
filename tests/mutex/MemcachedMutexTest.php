<?php

namespace malkusch\lock\mutex;

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

    protected function setUp()
    {
        $this->memcached = $this->createMock(\Memcached::class);
        $this->mutex = new MemcachedMutex('test', $this->memcached, 1);
    }

    /**
     * Tests failing to acquire the lock within the timeout.
     *
     * @expectedException \malkusch\lock\exception\TimeoutException
     */
    public function testFailAcquireLock()
    {
        $this->memcached->expects($this->atLeastOnce())
            ->method('add')
            ->with('lock_test', true, 2)
            ->willReturn(false);

        $this->mutex->synchronized(function (): void {
            $this->fail('execution is not expected');
        });
    }

    /**
     * Tests failing to release a lock.
     *
     * @expectedException \malkusch\lock\exception\LockReleaseException
     */
    public function testFailReleasingLock()
    {
        $this->memcached->expects($this->once())
            ->method('add')
            ->with('lock_test', true, 2)
            ->willReturn(true);

        $this->memcached->expects($this->once())
            ->method('delete')
            ->with('lock_test')
            ->willReturn(false);

        $this->mutex->synchronized(function (): void {
        });
    }
}
