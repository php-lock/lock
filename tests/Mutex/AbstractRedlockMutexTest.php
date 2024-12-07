<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Exception\MutexException;
use Malkusch\Lock\Exception\TimeoutException;
use Malkusch\Lock\Mutex\AbstractRedlockMutex;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\MockEnabledException;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @group redis
 */
#[Group('redis')]
class AbstractRedlockMutexTest extends TestCase
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
            \assert($e->getMessage() === 'microtime is already enabled.Call disable() on the existing mock.');
        }
    }

    /**
     * @param int $count The amount of redis apis
     *
     * @return AbstractRedlockMutex<object>&MockObject
     */
    private function createAbstractRedlockMutexMock(int $count, float $timeout = 1): AbstractRedlockMutex
    {
        $clients = array_map(
            static fn ($id) => ['id' => $id],
            range(1, $count)
        );

        return $this->getMockBuilder(AbstractRedlockMutex::class)
            ->setConstructorArgs([$clients, 'test', $timeout])
            ->onlyMethods(['add', 'evalScript'])
            ->getMock();
    }

    /**
     * Tests acquire() fails because too few servers are available.
     *
     * @param int $count     The total count of servers
     * @param int $available The count of available servers
     *
     * @dataProvider provideMinorityCases
     */
    #[DataProvider('provideMinorityCases')]
    public function testTooFewServerToAcquire(int $count, int $available): void
    {
        $this->expectException(LockAcquireException::class);
        $this->expectExceptionCode(MutexException::REDIS_NOT_ENOUGH_SERVERS);

        $mutex = $this->createAbstractRedlockMutexMock($count);

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
     * @param int $available The count of available servers
     *
     * @dataProvider provideMajorityCases
     */
    #[DataProvider('provideMajorityCases')]
    public function testFaultTolerance(int $count, int $available): void
    {
        $mutex = $this->createAbstractRedlockMutexMock($count);
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
     * @param int $available The count of available servers
     *
     * @dataProvider provideMinorityCases
     */
    #[DataProvider('provideMinorityCases')]
    public function testAcquireTooFewKeys(int $count, int $available): void
    {
        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Timeout of 1.0 seconds exceeded');

        $mutex = $this->createAbstractRedlockMutexMock($count);

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
     * @param int   $count   The total count of servers
     * @param float $timeout The timeout in seconds
     * @param float $delay   The delay in seconds
     *
     * @dataProvider provideTimingOutCases
     */
    #[DataProvider('provideTimingOutCases')]
    public function testTimingOut(int $count, float $timeout, float $delay): void
    {
        $timeoutStr = (string) round($timeout, 6);
        if (strpos($timeoutStr, '.') === false) {
            $timeoutStr .= '.0';
        }

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Timeout of ' . $timeoutStr . ' seconds exceeded');

        $mutex = $this->createAbstractRedlockMutexMock($count, $timeout);

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
     * @return iterable<list<mixed>>
     */
    public static function provideTimingOutCases(): iterable
    {
        yield [1, 1.2 - 1, 1.201];
        yield [2, 1.2 - 1, 1.401];
    }

    /**
     * Tests synchronized() works if the majority of keys was acquired.
     *
     * @param int $count     The total count of servers
     * @param int $available The count of available servers
     *
     * @dataProvider provideMajorityCases
     */
    #[DataProvider('provideMajorityCases')]
    public function testAcquireWithMajority(int $count, int $available): void
    {
        $mutex = $this->createAbstractRedlockMutexMock($count);
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
     * @param int $available The count of available servers
     *
     * @dataProvider provideMinorityCases
     */
    #[DataProvider('provideMinorityCases')]
    public function testTooFewServersToRelease(int $count, int $available): void
    {
        $mutex = $this->createAbstractRedlockMutexMock($count);
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
     * @param int $available The count of available servers
     *
     * @dataProvider provideMinorityCases
     */
    #[DataProvider('provideMinorityCases')]
    public function testReleaseTooFewKeys(int $count, int $available): void
    {
        $mutex = $this->createAbstractRedlockMutexMock($count);
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
     * @return iterable<list<mixed>>
     */
    public static function provideMinorityCases(): iterable
    {
        yield [1, 0];
        yield [2, 0];
        yield [2, 1];
        yield [3, 0];
        yield [3, 1];
        yield [4, 0];
        yield [4, 1];
        yield [4, 2];
    }

    /**
     * Provides test cases with enough.
     *
     * @return iterable<list<mixed>>
     */
    public static function provideMajorityCases(): iterable
    {
        yield [1, 1];
        yield [2, 2];
        yield [3, 2];
        yield [3, 3];
        yield [4, 3];
    }
}
