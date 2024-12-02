<?php

namespace malkusch\lock\Tests\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use malkusch\lock\exception\MutexException;
use malkusch\lock\exception\TimeoutException;
use malkusch\lock\mutex\RedisMutex;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @group redis
 */
class RedisMutexTest extends TestCase
{
    use PHPMock;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $sleepBuilder = new SleepEnvironmentBuilder();
        $sleepBuilder->addNamespace(__NAMESPACE__);
        $sleepBuilder->addNamespace('malkusch\lock\mutex');
        $sleepBuilder->addNamespace('malkusch\lock\util');
        $sleep = $sleepBuilder->build();

        $sleep->enable();
        $this->registerForTearDown($sleep);
    }

    /**
     * Builds a testable RedisMutex mock.
     *
     * @param int   $count   the amount of redis apis
     * @param float $timeout the timeout
     *
     * @return MockObject|RedisMutex
     */
    private function buildRedisMutex(int $count, float $timeout = 1)
    {
        $redisAPIs = array_map(
            static function ($id): array {
                return ['id' => $id];
            },
            range(1, $count)
        );

        return $this->getMockForAbstractClass(RedisMutex::class, [$redisAPIs, 'test', $timeout]);
    }

    /**
     * Tests acquire() fails because too few servers are available.
     *
     * @param int $count     The total count of servers
     * @param int $available the count of available servers
     *
     * @dataProvider provideMinorityCases
     */
    public function testTooFewServerToAcquire(int $count, int $available): void
    {
        $this->expectException(LockAcquireException::class);
        $this->expectExceptionCode(MutexException::REDIS_NOT_ENOUGH_SERVERS);

        $mutex = $this->buildRedisMutex($count);

        $i = 0;
        $mutex->expects(self::exactly($count))
            ->method('add')
            ->willReturnCallback(
                static function () use (&$i, $available): bool {
                    if ($i < $available) {
                        ++$i;

                        return true;
                    }

                    throw new LockAcquireException();
                }
            );

        $mutex->synchronized(static function (): void {
            self::fail('Code should not be executed');
        });
    }

    /**
     * Tests synchronized() does work if the majority of servers is up.
     *
     * @param int $count     The total count of servers
     * @param int $available the count of available servers
     *
     * @dataProvider provideMajorityCases
     */
    public function testFaultTolerance(int $count, int $available): void
    {
        $mutex = $this->buildRedisMutex($count);
        $mutex->expects(self::exactly($count))
            ->method('evalScript')
            ->willReturn(true);

        $i = 0;
        $mutex->expects(self::exactly($count))
            ->method('add')
            ->willReturnCallback(
                static function () use (&$i, $available): bool {
                    if ($i < $available) {
                        ++$i;

                        return true;
                    }

                    throw new LockAcquireException();
                }
            );

        $mutex->synchronized(static function () {});
    }

    /**
     * Tests too few keys could be acquired.
     *
     * @param int $count     The total count of servers
     * @param int $available the count of available servers
     *
     * @dataProvider provideMinorityCases
     */
    public function testAcquireTooFewKeys($count, $available): void
    {
        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Timeout of 1.0 seconds exceeded.');

        $mutex = $this->buildRedisMutex($count);

        $i = 0;
        $mutex->expects(self::any())
            ->method('add')
            ->willReturnCallback(
                static function () use (&$i, $available): bool {
                    ++$i;

                    return $i <= $available;
                }
            );

        $mutex->synchronized(static function (): void {
            self::fail('Code should not be executed');
        });
    }

    /**
     * Tests acquiring keys takes too long.
     *
     * @param int   $count   the total count of servers
     * @param float $timeout the timeout in seconds
     * @param float $delay   the delay in seconds
     *
     * @dataProvider provideTimingOutCases
     */
    public function testTimingOut(int $count, float $timeout, float $delay): void
    {
        $timeoutStr = (string) round($timeout, 6);
        if (strpos($timeoutStr, '.') === false) {
            $timeoutStr .= '.0';
        }

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage("Timeout of {$timeoutStr} seconds exceeded.");

        $mutex = $this->buildRedisMutex($count, $timeout);

        $mutex->expects(self::exactly($count))
            ->method('add')
            ->willReturnCallback(static function () use ($delay): bool {
                usleep((int) ($delay * 1e6));

                return true;
            });

        $mutex->synchronized(static function (): void {
            self::fail('Code should not be executed');
        });
    }

    /**
     * Returns test cases for testTimingOut().
     *
     * @return iterable<list<mixed>>
     */
    public static function provideTimingOutCases(): iterable
    {
        // count, timeout, delay
        return [
            [1, 1.2 - 1, 1.201],
            [2, 1.2 - 1, 1.401],
        ];
    }

    /**
     * Tests synchronized() works if the majority of keys was acquired.
     *
     * @param int $count     The total count of servers
     * @param int $available the count of available servers
     *
     * @dataProvider provideMajorityCases
     */
    public function testAcquireWithMajority(int $count, int $available): void
    {
        $mutex = $this->buildRedisMutex($count);
        $mutex->expects(self::exactly($count))
            ->method('evalScript')
            ->willReturn(true);

        $i = 0;
        $mutex->expects(self::exactly($count))
            ->method('add')
            ->willReturnCallback(
                static function () use (&$i, $available): bool {
                    ++$i;

                    return $i <= $available;
                }
            );

        $mutex->synchronized(static function (): void {});
    }

    /**
     * Tests releasing fails because too few servers are available.
     *
     * @param int $count     The total count of servers
     * @param int $available the count of available servers
     *
     * @dataProvider provideMinorityCases
     */
    public function testTooFewServersToRelease(int $count, int $available): void
    {
        $mutex = $this->buildRedisMutex($count);
        $mutex->expects(self::exactly($count))
            ->method('add')
            ->willReturn(true);

        $i = 0;
        $mutex->expects(self::exactly($count))
            ->method('evalScript')
            ->willReturnCallback(
                static function () use (&$i, $available): bool {
                    if ($i < $available) {
                        ++$i;

                        return true;
                    }

                    throw new LockReleaseException();
                }
            );

        $this->expectException(LockReleaseException::class);

        $mutex->synchronized(static function (): void {});
    }

    /**
     * Tests releasing too few keys.
     *
     * @param int $count     The total count of servers
     * @param int $available the count of available servers
     *
     * @dataProvider provideMinorityCases
     */
    public function testReleaseTooFewKeys(int $count, int $available): void
    {
        $mutex = $this->buildRedisMutex($count);
        $mutex->expects(self::exactly($count))
            ->method('add')
            ->willReturn(true);

        $i = 0;
        $mutex->expects(self::exactly($count))
            ->method('evalScript')
            ->willReturnCallback(
                static function () use (&$i, $available): bool {
                    ++$i;

                    return $i <= $available;
                }
            );

        $this->expectException(LockReleaseException::class);

        $mutex->synchronized(static function (): void {});
    }

    /**
     * Provides test cases with too few.
     *
     * @return int[][] test cases
     */
    public static function provideMinorityCases(): iterable
    {
        // total count, available count
        return [
            [1, 0],
            [2, 0],
            [2, 1],
            [3, 0],
            [3, 1],
            [4, 0],
            [4, 1],
            [4, 2],
        ];
    }

    /**
     * Provides test cases with enough.
     *
     * @return int[][] test cases
     */
    public static function provideMajorityCases(): iterable
    {
        // total count, available count
        return [
            [1, 1],
            [2, 2],
            [3, 2],
            [3, 3],
            [4, 3],
        ];
    }
}
