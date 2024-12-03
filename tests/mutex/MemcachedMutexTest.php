<?php

namespace malkusch\lock\Tests\mutex;

use malkusch\lock\exception\LockReleaseException;
use malkusch\lock\exception\TimeoutException;
use malkusch\lock\mutex\MemcachedMutex;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Please provide the environment variable MEMCACHE_HOST.
 *
 * @requires extension memcached
 */
class MemcachedMutexTest extends TestCase
{
    /** @var \Memcached|MockObject */
    protected $memcached;

    /** @var MemcachedMutex */
    private $mutex;

    #[\Override]
    protected function setUp(): void
    {
        $this->memcached = $this->createMock(\Memcached::class);
        $this->mutex = new MemcachedMutex('test', $this->memcached, 1);
    }

    /**
     * Tests failing to acquire the lock within the timeout.
     */
    public function testFailAcquireLock(): void
    {
        $this->expectException(TimeoutException::class);

        $this->memcached->expects(self::atLeastOnce())
            ->method('add')
            ->with('lock_test', true, 2)
            ->willReturn(false);

        $this->mutex->synchronized(static function (): void {
            self::fail('execution is not expected');
        });
    }

    /**
     * Tests failing to release a lock.
     */
    public function testFailReleasingLock(): void
    {
        $this->expectException(LockReleaseException::class);

        $this->memcached->expects(self::once())
            ->method('add')
            ->with('lock_test', true, 2)
            ->willReturn(true);

        $this->memcached->expects(self::once())
            ->method('delete')
            ->with('lock_test')
            ->willReturn(false);

        $this->mutex->synchronized(static function (): void {});
    }
}
