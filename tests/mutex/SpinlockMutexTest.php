<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\ExecutionOutsideLockException;
use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use malkusch\lock\exception\TimeoutException;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SpinlockMutex.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 * @see SpinlockMutex
 */
class SpinlockMutexTest extends TestCase
{
    use PHPMock;

    protected function setUp(): void
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
     */
    public function testFailAcquireLock()
    {
        $this->expectException(LockAcquireException::class);

        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ['test']);
        $mutex->expects($this->any())
            ->method('acquire')
            ->willThrowException(new LockAcquireException());

        $mutex->synchronized(function () {
            $this->fail('execution is not expected');
        });
    }

    /**
     * Tests failing to acquire the lock due to a timeout.
     */
    public function testAcquireTimesOut()
    {
        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Timeout of 3 seconds exceeded.');

        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ['test']);
        $mutex->expects($this->atLeastOnce())
            ->method('acquire')
            ->willReturn(false);

        $mutex->synchronized(function () {
            $this->fail('execution is not expected');
        });
    }

    /**
     * Tests executing code which exceeds the timeout fails.
     *
     */
    public function testExecuteTooLong()
    {
        /** @var SpinlockMutex|\PHPUnit\Framework\MockObject\MockObject $mutex */
        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ['test', 1]);
        $mutex->expects($this->any())
            ->method('acquire')
            ->willReturn(true);

        $mutex->expects($this->any())
            ->method('release')
            ->willReturn(true);

        $this->expectException(ExecutionOutsideLockException::class);
//        $this->expectExceptionMessageRegExp(
//            '/The code executed for \d+\.\d+ seconds. But the timeout is 1 ' .
//            'seconds. The last \d+\.\d+ seconds were executed outside of the lock./'
//        );

        $mutex->synchronized(function () {
            usleep(1100 * 1000);
        });
    }

    /**
     * Tests executing code which barely doesn't hit the timeout.
     *
     */
    public function testExecuteBarelySucceeds()
    {
        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ['test', 1]);
        $mutex->expects($this->any())->method('acquire')->willReturn(true);
        $mutex->expects($this->once())->method('release')->willReturn(true);

        $mutex->synchronized(function () {
            usleep(999999);
        });
    }

    /**
     * Tests failing to release a lock.
     */
    public function testFailReleasingLock()
    {
        $this->expectException(LockReleaseException::class);

        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ['test']);
        $mutex->expects($this->any())->method('acquire')->willReturn(true);
        $mutex->expects($this->any())->method('release')->willReturn(false);

        $mutex->synchronized(function () {
        });
    }

    /**
     * Tests executing exactly until the timeout will leave the key one more second.
     */
    public function testExecuteTimeoutLeavesOneSecondForKeyToExpire()
    {
        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ['test', 3]);
        $mutex->expects($this->once())
            ->method('acquire')
            ->with($this->anything(), 4)
            ->willReturn(true);

        $mutex->expects($this->once())->method('release')->willReturn(true);

        $mutex->synchronized(function () {
            usleep(2999999);
        });
    }
}
