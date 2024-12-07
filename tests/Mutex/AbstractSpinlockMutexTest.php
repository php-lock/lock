<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

use Malkusch\Lock\Exception\ExecutionOutsideLockException;
use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Mutex\AbstractSpinlockMutex;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\MockEnabledException;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AbstractSpinlockMutexTest extends TestCase
{
    use PHPMock;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $sleepBuilder = new SleepEnvironmentBuilder();
        $sleepBuilder->addNamespace(__NAMESPACE__);
        $sleepBuilder->addNamespace('Malkusch\Lock\Mutex');
        $sleepBuilder->addNamespace('Malkusch\Lock\Util');
        $sleep = $sleepBuilder->build();
        try {
            $sleep->enable();
            $this->registerForTearDown($sleep);
        } catch (MockEnabledException $e) {
            // workaround for burn testing
            \assert($e->getMessage() === 'microtime is already enabled. Call disable() on the existing mock.');
        }
    }

    /**
     * @return AbstractSpinlockMutex&MockObject
     */
    private function createSpinlockMutexMock(float $timeout = 3): AbstractSpinlockMutex
    {
        return $this->getMockBuilder(AbstractSpinlockMutex::class)
            ->setConstructorArgs(['test', $timeout])
            ->onlyMethods(['acquire', 'release'])
            ->getMock();
    }

    /**
     * Tests failing to acquire the lock.
     */
    public function testFailAcquireLock(): void
    {
        $this->expectException(LockAcquireException::class);

        $mutex = $this->createSpinlockMutexMock();
        $mutex->expects(self::any())
            ->method('acquire')
            ->willThrowException(new LockAcquireException());

        $mutex->synchronized(static function () {
            self::fail();
        });
    }

    /**
     * Tests failing to acquire the lock due to a timeout.
     */
    public function testAcquireTimeouts(): void
    {
        $this->expectException(LockAcquireTimeoutException::class);
        $this->expectExceptionMessage('Lock acquire timeout of 3.0 seconds has been exceeded');

        $mutex = $this->createSpinlockMutexMock();
        $mutex->expects(self::atLeastOnce())
            ->method('acquire')
            ->willReturn(false);

        $mutex->synchronized(static function () {
            self::fail();
        });
    }

    /**
     * Tests executing code which exceeds the timeout fails.
     */
    public function testExecuteTooLong(): void
    {
        $mutex = $this->createSpinlockMutexMock(0.5);
        $mutex->expects(self::any())
            ->method('acquire')
            ->willReturn(true);

        $mutex->expects(self::any())
            ->method('release')
            ->willReturn(true);

        $this->expectException(ExecutionOutsideLockException::class);
        $this->expectExceptionMessageMatches('~^The code executed for 0\.5\d+ seconds\. But the timeout is 0\.5 seconds. The last 0\.0\d+ seconds were executed outside of the lock\.$~');

        $mutex->synchronized(static function () {
            usleep(501 * 1000);
        });
    }

    /**
     * Tests executing code which barely doesn't hit the timeout.
     */
    public function testExecuteBarelySucceeds(): void
    {
        $mutex = $this->createSpinlockMutexMock(0.5);
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

        $mutex = $this->createSpinlockMutexMock();
        $mutex->expects(self::any())->method('acquire')->willReturn(true);
        $mutex->expects(self::any())->method('release')->willReturn(false);

        $mutex->synchronized(static function () {});
    }

    /**
     * Tests executing exactly until the timeout will leave the key one more second.
     */
    public function testExecuteTimeoutLeavesOneSecondForKeyToExpire(): void
    {
        $mutex = $this->createSpinlockMutexMock(0.2);
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
