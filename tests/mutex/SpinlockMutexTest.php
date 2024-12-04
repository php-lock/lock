<?php

namespace malkusch\lock\Tests\mutex;

use malkusch\lock\exception\ExecutionOutsideLockException;
use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use malkusch\lock\exception\TimeoutException;
use malkusch\lock\mutex\SpinlockMutex;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SpinlockMutexTest extends TestCase
{
    use PHPMock;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $builder = new SleepEnvironmentBuilder();
        $builder->addNamespace(__NAMESPACE__);
        $builder->addNamespace('malkusch\lock\mutex');
        $builder->addNamespace('malkusch\lock\util');
        $sleep = $builder->build();
        $sleep->enable();

        $this->registerForTearDown($sleep);
    }

    /**
     * Tests failing to acquire the lock.
     */
    public function testFailAcquireLock(): void
    {
        $this->expectException(LockAcquireException::class);

        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ['test']);
        $mutex->expects(self::any())
            ->method('acquire')
            ->willThrowException(new LockAcquireException());

        $mutex->synchronized(static function () {
            self::fail('execution is not expected');
        });
    }

    /**
     * Tests failing to acquire the lock due to a timeout.
     */
    public function testAcquireTimesOut(): void
    {
        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Timeout of 3.0 seconds exceeded');

        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ['test']);
        $mutex->expects(self::atLeastOnce())
            ->method('acquire')
            ->willReturn(false);

        $mutex->synchronized(static function () {
            self::fail('execution is not expected');
        });
    }

    /**
     * Tests executing code which exceeds the timeout fails.
     */
    public function testExecuteTooLong(): void
    {
        /** @var SpinlockMutex|MockObject $mutex */
        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ['test', 0.5]); // @phpstan-ignore varTag.nativeType
        $mutex->expects(self::any())
            ->method('acquire')
            ->willReturn(true);

        $mutex->expects(self::any())
            ->method('release')
            ->willReturn(true);

        $this->expectException(ExecutionOutsideLockException::class);
        $this->expectExceptionMessageMatches(
            '/The code executed for 0\.5\d+ seconds. But the timeout is 0\.5 ' .
            'seconds. The last 0\.0\d+ seconds were executed outside of the lock./'
        );

        $mutex->synchronized(static function () {
            usleep(501 * 1000);
        });
    }

    /**
     * Tests executing code which barely doesn't hit the timeout.
     */
    public function testExecuteBarelySucceeds(): void
    {
        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ['test', 0.5]);
        $mutex->expects(self::any())->method('acquire')->willReturn(true);
        $mutex->expects(self::once())->method('release')->willReturn(true);

        $mutex->synchronized(static function () {
            usleep(499 * 1000);
        });
    }

    /**
     * Tests failing to release a lock.
     */
    public function testFailReleasingLock(): void
    {
        $this->expectException(LockReleaseException::class);

        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ['test']);
        $mutex->expects(self::any())->method('acquire')->willReturn(true);
        $mutex->expects(self::any())->method('release')->willReturn(false);

        $mutex->synchronized(static function () {});
    }

    /**
     * Tests executing exactly until the timeout will leave the key one more second.
     */
    public function testExecuteTimeoutLeavesOneSecondForKeyToExpire(): void
    {
        $mutex = $this->getMockForAbstractClass(SpinlockMutex::class, ['test', 0.2]);
        $mutex->expects(self::once())
            ->method('acquire')
            ->with(self::anything(), 1.2)
            ->willReturn(true);

        $mutex->expects(self::once())->method('release')->willReturn(true);

        $mutex->synchronized(static function () {
            usleep(199 * 1000);
        });
    }
}
