<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LockMutex.
 */
class LockMutexTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|LockMutex The SUT
     */
    private $mutex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mutex = $this->getMockForAbstractClass(LockMutex::class);
    }

    /**
     * Tests lock() fails and the code is not executed.
     */
    public function testLockFails()
    {
        $this->expectException(LockAcquireException::class);

        $this->mutex->expects($this->once())
            ->method('lock')
            ->willThrowException(new LockAcquireException());

        $this->mutex->synchronized(function (): void {
            $this->fail('Should not execute code.');
        });
    }

    /**
     * Tests unlock() is called after the code was executed.
     */
    public function testUnlockAfterCode()
    {
        $this->mutex->expects($this->once())
            ->method('unlock');

        $this->mutex->synchronized(function (): void {});
    }

    /**
     * Tests unlock() is called after an exception.
     */
    public function testUnlockAfterException()
    {
        $this->mutex->expects($this->once())
            ->method('unlock');

        $this->expectException(\DomainException::class);
        $this->mutex->synchronized(function () {
            throw new \DomainException();
        });
    }

    /**
     * Tests unlock() fails after the code was executed.
     */
    public function testUnlockFailsAfterCode()
    {
        $this->expectException(LockReleaseException::class);

        $this->mutex->expects($this->once())
            ->method('unlock')
            ->willThrowException(new LockReleaseException());

        $this->mutex->synchronized(function () {});
    }

    /**
     * Tests unlock() fails after the code threw an exception.
     */
    public function testUnlockFailsAfterException()
    {
        $this->expectException(LockReleaseException::class);

        $this->mutex->expects($this->once())
            ->method('unlock')
            ->willThrowException(new LockReleaseException());

        $this->mutex->synchronized(function () {
            throw new \DomainException();
        });
    }

    /**
     * Tests the code result is available in LockReleaseException.
     */
    public function testCodeResultAvailableAfterFailedUnlock()
    {
        $this->mutex->expects($this->once())
            ->method('unlock')
            ->willThrowException(new LockReleaseException());

        try {
            $this->mutex->synchronized(function () {
                return 'result';
            });
        } catch (LockReleaseException $exception) {
            $this->assertEquals('result', $exception->getCodeResult());
            $this->assertNull($exception->getCodeException());
        }
    }

    /**
     * Tests the code exception is available in LockReleaseException.
     */
    public function testCodeExceptionAvailableAfterFailedUnlock()
    {
        $this->mutex->expects($this->once())
            ->method('unlock')
            ->willThrowException(new LockReleaseException());

        try {
            $this->mutex->synchronized(function () {
                throw new \DomainException('Domain exception');
            });
        } catch (LockReleaseException $exception) {
            $this->assertInstanceOf(\DomainException::class, $exception->getCodeException());
            $this->assertEquals('Domain exception', $exception->getCodeException()->getMessage());
        }
    }
}
