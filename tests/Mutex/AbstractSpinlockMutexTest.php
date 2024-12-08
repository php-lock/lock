<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

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
    private function createSpinlockMutexMock(float $acquireTimeout = 3): AbstractSpinlockMutex
    {
        return $this->getMockBuilder(AbstractSpinlockMutex::class)
            ->setConstructorArgs(['test', $acquireTimeout])
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
     * Tests executing code which barely doesn't hit the acquire timeout.
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
}
