<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Exception\MutexException;
use Malkusch\Lock\Mutex\AbstractSpinlockWithTokenMutex;
use Malkusch\Lock\Mutex\DistributedMutex;
use Malkusch\Lock\Mutex\RedisMutex;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DistributedMutexTest extends TestCase
{
    use PHPMock;

    public function testAddFails(): void
    {
        $mutex = new DistributedMutex(array_map(static function (string $v) {
            return new class(new \stdClass(), 'test') extends RedisMutex {
                #[\Override]
                protected function acquireWithToken(string $key, float $expireTimeout)
                {
                    throw new LockAcquireException('xxx');
                }

                #[\Override]
                protected function releaseWithToken(string $key, string $token): bool
                {
                    throw new LockReleaseException('yyy');
                }
            };
        }, ['a', 'b']));

        $this->expectException(LockAcquireException::class);
        $this->expectExceptionCode(MutexException::CODE_REDLOCK_NOT_ENOUGH_SERVERS);
        $mutex->synchronized(static function () {
            self::fail();
        });
    }

    /**
     * Tests too few keys could be acquired.
     *
     * @param int $count     The total count of servers
     * @param int $available The count of available servers
     *
     * @dataProvider provideAcquireTooFewKeysCases
     */
    #[DataProvider('provideAcquireTooFewKeysCases')]
    public function testAcquireTooFewKeys(int $count, int $available): void
    {
        var_dump('x ' . (memory_get_usage() / (1024 * 1024)));

        $sleepBuilder = new SleepEnvironmentBuilder();
        $sleepBuilder->addNamespace('Malkusch\Lock\Mutex');
        $sleepBuilder->addNamespace('Malkusch\Lock\Util');
        $sleep = $sleepBuilder->build();
        $sleep->enable();
        $this->registerForTearDown($sleep);

        global $cc;
        $cc = 0;

        $mutexes = array_map(
            function (int $i) {
                $mutex = $this->getMockBuilder(AbstractSpinlockWithTokenMutex::class)
                    ->setConstructorArgs(['test', \INF])
                    ->onlyMethods(['acquireWithToken2'])
                    ->getMock();

                /* $mutex
                    ->method('acquireWithToken2')
                    ->willReturn('x'); */

                return $mutex;
            },
            range(0, $count - 1)
        );

        $mutex = $this->getMockBuilder(DistributedMutex::class)
            ->setConstructorArgs([$mutexes, 1, \INF])
            ->onlyMethods(['acquireMutex'])
            ->getMock();

        $i = 0;
        $mutex
            ->method('acquireMutex')
            /* ->willReturnCallback(static function () use (&$i, $available) {
                return ++$i <= $available;
            }) */;

        /* $mutex = new class($mutexes, 1, \INF) extends DistributedMutex {};
        $mutex->available = $available; */

        $this->expectException(LockAcquireTimeoutException::class);
        $this->expectExceptionMessage('Lock acquire timeout of 1.0 seconds has been exceeded');
        $mutex->synchronized(static function () {
            self::fail();
        });
    }

    /**
     * Provides test cases with too few.
     *
     * @return iterable<list<mixed>>
     */
    public static function provideAcquireTooFewKeysCases(): iterable
    {
        yield [1, 0];
        yield [2, 0];
        yield [2, 1];
        yield [3, 0];
        yield [3, 1];
        yield [4, 0];
        yield [4, 1];
        yield [4, 2];
        yield [5, 2];
        yield [6, 2];
        yield [6, 3];
    }
}
