<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LockMutex.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 * @see LockMutex
 */
class LockMutexTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject The SUT
     */
    private $mutex;
    
    protected function setUp()
    {
        parent::setUp();
        
        $this->mutex = $this->getMockForAbstractClass(LockMutex::class);
    }

    /**
     * Tests lock() fails and the code is not executed.
     *
     * @expectedException malkusch\lock\exception\LockAcquireException
     */
    public function testLockFails()
    {
        $this->mutex->expects($this->once())
            ->method("lock")
            ->willThrowException(new LockAcquireException());
        
        $this->mutex->synchronized(function () {
            $this->fail("Should not execute code.");
        });
    }
    
    /**
     * Tests unlock() is called after the code was executed.
     *
     */
    public function testUnlockAfterCode()
    {
        $this->mutex->expects($this->once())
            ->method("unlock");
        
        $this->mutex->synchronized(function (): void {
        });
    }
    
    /**
     * Tests unlock() is called after an exception.
     *
     */
    public function testUnlockAfterException()
    {
        $this->mutex->expects($this->once())
            ->method("unlock");
        

        $this->expectException(\DomainException::class);
        $this->mutex->synchronized(function () {
            throw new \DomainException();
        });
    }
    
    /**
     * Tests unlock() fails after the code was executed.
     *
     * @expectedException malkusch\lock\exception\LockReleaseException
     */
    public function testUnlockFailsAfterCode()
    {
        $this->mutex->expects($this->once())
            ->method("unlock")
            ->willThrowException(new LockReleaseException());
        
        $this->mutex->synchronized(function () {
        });
    }
    
    /**
     * Tests unlock() fails after the code threw an exception.
     *
     * The previous exception should be the code's exception.
     *
     * @expectedException malkusch\lock\exception\LockReleaseException
     */
    public function testUnlockFailsAfterException()
    {
        $this->mutex->expects($this->any())
            ->method("unlock")
            ->willThrowException(new LockReleaseException());
        
        $this->mutex->synchronized(function () {
            throw new \DomainException();
        });
    }
}
