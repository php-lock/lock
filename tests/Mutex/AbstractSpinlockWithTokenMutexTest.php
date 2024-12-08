<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

use Malkusch\Lock\Exception\ExecutionOutsideLockException;
use Malkusch\Lock\Mutex\AbstractSpinlockWithTokenMutex;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\MockEnabledException;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AbstractSpinlockWithTokenMutexTest extends TestCase
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
     * @return AbstractSpinlockWithTokenMutex&MockObject
     */
    private function createSpinlockWithTokenMutexMock(float $acquireTimeout = 3, float $expireTimeout = \INF): AbstractSpinlockWithTokenMutex
    {
        return $this->getMockBuilder(AbstractSpinlockWithTokenMutex::class)
            ->setConstructorArgs(['test', $acquireTimeout, $expireTimeout])
            ->onlyMethods(['acquireWithToken', 'releaseWithToken'])
            ->getMock();
    }

    public function testExecuteExpireTimeout(): void
    {
        $mutex = $this->createSpinlockWithTokenMutexMock(0.1, 0.2);
        $mutex->expects(self::once())
            ->method('acquireWithToken')
            ->with(self::anything(), 0.2)
            ->willReturn('xx');

        $mutex->expects(self::once())
            ->method('releaseWithToken')
            ->with(self::anything(), 'xx')
            ->willReturn(true);

        $mutex->synchronized(static function () {
            usleep(199 * 1000);
        });
    }

    public function testExecuteTooLong(): void
    {
        $mutex = $this->createSpinlockWithTokenMutexMock(0.1, 0.2);
        $mutex->expects(self::any())
            ->method('acquireWithToken')
            ->with(self::anything(), 0.2)
            ->willReturn('xx');

        $mutex->expects(self::any())
            ->method('releaseWithToken')
            ->willReturn(true);

        $this->expectException(ExecutionOutsideLockException::class);
        $this->expectExceptionMessageMatches('~^The code executed for 0\.2\d+ seconds\. But the expire timeout is 0\.2 seconds. The last 0\.0\d+ seconds were executed outside of the lock\.$~');

        $mutex->synchronized(static function () {
            usleep(201 * 1000);
        });
    }
}
