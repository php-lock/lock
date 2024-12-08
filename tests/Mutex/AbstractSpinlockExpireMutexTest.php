<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

use Malkusch\Lock\Exception\ExecutionOutsideLockException;
use Malkusch\Lock\Mutex\AbstractSpinlockExpireMutex;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\MockEnabledException;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AbstractSpinlockExpireMutexTest extends TestCase
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
     * @return AbstractSpinlockExpireMutex&MockObject
     */
    private function createSpinlockExpireMutexMock(float $acquireTimeout = 3, float $expireTimeout = \PHP_INT_MAX): AbstractSpinlockExpireMutex
    {
        return $this->getMockBuilder(AbstractSpinlockExpireMutex::class)
            ->setConstructorArgs(['test', $acquireTimeout, $expireTimeout])
            ->onlyMethods(['acquireWithToken', 'releaseWithToken'])
            ->getMock();
    }

    /**
     * Tests executing code which exceeds the acquire timeout fails.
     */
    public function testExecuteTooLong(): void
    {
        $mutex = $this->createSpinlockExpireMutexMock(0.5);
        $mutex->expects(self::any())
            ->method('acquireWithToken')
            ->willReturn('xx');

        $mutex->expects(self::any())
            ->method('releaseWithToken')
            ->willReturn(true);

        $this->expectException(ExecutionOutsideLockException::class);
        $this->expectExceptionMessageMatches('~^The code executed for 0\.5\d+ seconds\. But the timeout is 0\.5 seconds. The last 0\.0\d+ seconds were executed outside of the lock\.$~');

        $mutex->synchronized(static function () {
            usleep(501 * 1000);
        });
    }

    /**
     * Tests executing exactly until the timeout will leave the key one more second.
     */
    public function testExecuteTimeoutLeavesOneSecondForKeyToExpire(): void
    {
        $mutex = $this->createSpinlockExpireMutexMock(0.2, 0.3);
        $mutex->expects(self::once())
            ->method('acquireWithToken')
            ->with(self::anything(), 1.3)
            ->willReturn('xx');

        $mutex->expects(self::once())->method('releaseWithToken')->willReturn(true);

        $mutex->synchronized(static function () {
            usleep(199 * 1000);
        });
    }
}
