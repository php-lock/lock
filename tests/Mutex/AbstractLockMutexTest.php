<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Mutex\AbstractLockMutex;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AbstractLockMutexTest extends TestCase
{
    /** @var AbstractLockMutex&MockObject */
    private $mutex;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mutex = $this->getMockBuilder(AbstractLockMutex::class)
            ->onlyMethods(['lock', 'unlock'])
            ->getMock();
    }

    /**
     * Tests lock() fails and the code is not executed.
     */
    public function testLockFails(): void
    {
        $this->mutex->expects(self::once())
            ->method('lock')
            ->willThrowException(new LockAcquireException());

        $this->expectException(LockAcquireException::class);
        $this->mutex->synchronized(static function () {
            self::fail();
        });
    }

    /**
     * Tests unlock() is called after the code was executed.
     */
    public function testUnlockAfterCode(): void
    {
        $this->mutex->expects(self::once())
            ->method('unlock');

        $this->mutex->synchronized(static function () {});
    }

    /**
     * Tests unlock() is called after an exception.
     */
    public function testUnlockAfterException(): void
    {
        $this->mutex->expects(self::once())
            ->method('unlock');

        $this->expectException(\DomainException::class);
        $this->mutex->synchronized(static function () {
            throw new \DomainException();
        });
    }

    /**
     * Tests unlock() fails after the code was executed.
     */
    public function testUnlockFailsAfterCode(): void
    {
        $this->mutex->expects(self::once())
            ->method('unlock')
            ->willThrowException(new LockReleaseException());

        $this->expectException(LockReleaseException::class);
        $this->mutex->synchronized(static function () {});
    }

    /**
     * Tests unlock() fails after the code threw an exception.
     */
    public function testUnlockFailsAfterException(): void
    {
        $this->mutex->expects(self::once())
            ->method('unlock')
            ->willThrowException(new LockReleaseException());

        $this->expectException(LockReleaseException::class);
        $this->mutex->synchronized(static function () {
            throw new \DomainException();
        });
    }

    /**
     * Tests the code result is available in LockReleaseException.
     */
    public function testCodeResultAvailableAfterFailedUnlock(): void
    {
        $this->mutex->expects(self::once())
            ->method('unlock')
            ->willThrowException(new LockReleaseException());

        try {
            $this->mutex->synchronized(static function () {
                return 'result';
            });
        } catch (LockReleaseException $exception) {
            self::assertSame('result', $exception->getCodeResult());
            self::assertNull($exception->getCodeException());
        }
    }

    /**
     * Tests the code exception is available in LockReleaseException.
     */
    public function testCodeExceptionAvailableAfterFailedUnlock(): void
    {
        $this->mutex->expects(self::once())
            ->method('unlock')
            ->willThrowException(new LockReleaseException());

        try {
            $this->mutex->synchronized(static function () {
                throw new \DomainException('Domain exception');
            });
        } catch (LockReleaseException $exception) {
            self::assertInstanceOf(\DomainException::class, $exception->getCodeException());
            self::assertSame('Domain exception', $exception->getCodeException()->getMessage());
        }
    }
}
