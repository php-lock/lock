<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Exception\MutexException;
use Malkusch\Lock\Mutex\AbstractRedlockMutex;
use Malkusch\Lock\Util\LockUtil;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\MockEnabledException;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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
            \assert($e->getMessage() === 'microtime is already enabled. Call disable() on the existing mock.');
        }
    }

    /**
     * @param int $count The amount of redis APIs
     *
     * @return AbstractRedlockMutex<object>&MockObject
     */
    private function createRedlockMutexMock(int $count, float $acquireTimeout = 1, float $expireTimeout = \INF): AbstractRedlockMutex
    {
        $clients = array_map(
            static fn ($i) => new class($i) {
                public int $i;

                public function __construct(int $i)
                {
                    $this->i = $i;
                }
            },
            range(0, $count - 1)
        );

        return $this->getMockBuilder(AbstractRedlockMutex::class)
            ->setConstructorArgs([$clients, 'test', $acquireTimeout, $expireTimeout])
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
        $this->expectExceptionCode(MutexException::CODE_REDLOCK_NOT_ENOUGH_SERVERS);

        $mutex = $this->createRedlockMutexMock($count);

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
            self::fail();
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
        $mutex = $this->createRedlockMutexMock($count);
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
        $this->expectException(LockAcquireTimeoutException::class);
        $this->expectExceptionMessage('Lock acquire timeout of 1.0 seconds has been exceeded');

        $mutex = $this->createRedlockMutexMock($count);

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
            self::fail();
        });
    }

    /**
     * Tests acquiring keys takes too long.
     *
     * @param int   $count   The total count of servers
     * @param float $timeout In seconds
     * @param float $delay   In seconds
     *
     * @dataProvider provideAcquireTimeoutsCases
     */
    #[DataProvider('provideAcquireTimeoutsCases')]
    public function testAcquireTimeouts(int $count, float $timeout, float $delay): void
    {
        $this->expectException(LockAcquireTimeoutException::class);
        $this->expectExceptionMessage('Lock acquire timeout of ' . LockUtil::getInstance()->formatTimeout($timeout) . ' seconds has been exceeded');

        $mutex = $this->createRedlockMutexMock($count, $timeout);

        $mutex->expects(self::exactly($count))
            ->method('add')
            ->willReturnCallback(static function () use ($delay): bool {
                usleep((int) ($delay * 1e6));

                return true;
            });

        $mutex->synchronized(static function (): void {
            self::fail();
        });
    }

    /**
     * @return iterable<list<mixed>>
     */
    public static function provideAcquireTimeoutsCases(): iterable
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
        $mutex = $this->createRedlockMutexMock($count);
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
        $mutex = $this->createRedlockMutexMock($count);
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
        $mutex = $this->createRedlockMutexMock($count);
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
