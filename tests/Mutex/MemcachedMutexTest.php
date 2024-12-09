<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Mutex\MemcachedMutex;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Please provide the environment variable MEMCACHE_HOST.
 *
 * @requires extension memcached
 */
#[RequiresPhpExtension('memcached')]
class MemcachedMutexTest extends TestCase
{
    /** @var \Memcached&MockObject */
    protected $memcached;

    /** @var MemcachedMutex */
    private $mutex;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->memcached = $this->createMock(\Memcached::class);
        $this->mutex = new MemcachedMutex('test', $this->memcached, 1, 2);
    }

    public function testAcquireFail(): void
    {
        $this->memcached->expects(self::atLeastOnce())
            ->method('add')
            ->with('php-malkusch-lock:test', true, 3)
            ->willReturn(false);

        $this->expectException(LockAcquireTimeoutException::class);
        $this->mutex->synchronized(static function () {
            self::fail();
        });
    }

    public function testReleaseFail(): void
    {
        $this->memcached->expects(self::once())
            ->method('add')
            ->with('php-malkusch-lock:test', true, 3)
            ->willReturn(true);

        $this->memcached->expects(self::once())
            ->method('delete')
            ->with('php-malkusch-lock:test')
            ->willReturn(false);

        $this->expectException(LockReleaseException::class);
        $this->mutex->synchronized(static function () {});
    }

    public function testAcquireExpireTimeoutLimit(): void
    {
        $this->mutex = new MemcachedMutex('test', $this->memcached);

        $this->memcached->expects(self::once())
            ->method('add')
            ->with('php-malkusch-lock:test', true, 0)
            ->willReturn(true);

        $this->memcached->expects(self::once())
            ->method('delete')
            ->with('php-malkusch-lock:test')
            ->willReturn(true);

        $this->mutex->synchronized(static function () {});
    }
}
