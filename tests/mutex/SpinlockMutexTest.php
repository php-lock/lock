<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\ExecutionOutsideLockException;
use malkusch\lock\exception\LockAcquireException;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\phpunit\PHPMock;

/**
 * Tests for SpinlockMutex.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 * @see SpinlockMutex
 */
class SpinlockMutexTest extends \PHPUnit_Framework_TestCase
{
    
    use PHPMock;
    
    protected function setUp()
    {
        parent::setUp();
        
        $builder = new SleepEnvironmentBuilder();
        $builder->addNamespace(__NAMESPACE__);
        $builder->addNamespace('malkusch\lock\util');
        $sleep = $builder->build();
        $sleep->enable();
        
        $this->registerForTearDown($sleep);
    }
    
    /**
     * Tests failing to acquire the lock.
     *
     * @test
     * @expectedException \malkusch\lock\exception\LockAcquireException
     */
    public function testFailAcquireLock()
    {
        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ["test"]);
        $mutex->expects($this->any())->method("acquire")->willThrowException(new LockAcquireException());

        $mutex->synchronized(function () {
            $this->fail("execution is not expected");
        });
    }
    
    /**
     * Tests failing to acquire the lock due to a timeout.
     *
     * @test
     * @expectedException malkusch\lock\exception\TimeoutException
     */
    public function testAcquireTimesOut()
    {
        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ["test"]);
        $mutex->expects($this->any())->method("acquire")->willReturn(false);

        $mutex->synchronized(function () {
            $this->fail("execution is not expected");
        });
    }
    
    /**
     * Tests executing code which exceeds the timeout fails.
     *
     * @test
     */
    public function testExecuteTooLong()
    {
        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ["test", 1]);
        $mutex->expects($this->any())->method("acquire")->willReturn(true);
        $mutex->expects($this->any())->method("release")->willReturn(true);

        $this->expectException(ExecutionOutsideLockException::class);
        $this->expectExceptionMessageRegExp(
            '/The code executed for \d+\.\d+ seconds. But the timeout is 1 ' .
            'seconds. The last \d+\.\d+ seconds were executed outside the lock./'
        );

        $mutex->synchronized(function () {
            sleep(1);
        });
    }
    
    /**
     * Tests executing code which barely doesn't hit the timeout.
     *
     * @test
     */
    public function testExecuteBarelySucceeds()
    {
        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ["test", 1]);
        $mutex->expects($this->any())->method("acquire")->willReturn(true);
        $mutex->expects($this->once())->method("release")->willReturn(true);

        $mutex->synchronized(function () {
            usleep(999999);
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
        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ["test"]);
        $mutex->expects($this->any())->method("acquire")->willReturn(true);
        $mutex->expects($this->any())->method("release")->willReturn(false);
        
        $mutex->synchronized(function () {
        });
    }
    
    /**
     * Tests executing exactly unitl the timeout will leave the key one more second.
     *
     * @test
     */
    public function testExecuteTimeoutLeavesOneSecondForKeyToExpire()
    {
        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ["test", 3]);
        $mutex->expects($this->once())
            ->method("acquire")
            ->with($this->anything(), 4)
            ->willReturn(true);
        
        $mutex->expects($this->once())->method("release")->willReturn(true);
        
        $mutex->synchronized(function () {
            usleep(2999999);
        });
    }
}
