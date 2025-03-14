<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Exception\MutexException;
use Malkusch\Lock\Mutex\AbstractSpinlockMutex;
use Malkusch\Lock\Mutex\AbstractSpinlockWithTokenMutex;
use Malkusch\Lock\Mutex\DistributedMutex;
use Malkusch\Lock\Util\LockUtil;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\PredisException;
use Psr\Log\LoggerInterface;

class DistributedMutexTest extends TestCase
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
        $sleep->enable();
        $this->registerForTearDown($sleep);
    }

    /**
     * @param int $count The amount of redis APIs
     *
     * @return DistributedMutex&MockObject
     */
    private function createDistributedMutexMock(int $count, float $acquireTimeout = 1, float $expireTimeout = \INF): DistributedMutex
    {
        $mutexes = array_map(
            function (int $i) {
                $mutex = $this->getMockBuilder(AbstractSpinlockWithTokenMutex::class)
                    ->setConstructorArgs(['test', \INF])
                    ->onlyMethods(['acquireWithToken', 'releaseWithToken'])
                    ->getMock();

                $mutex
                    ->method('acquireWithToken')
                    ->with(self::anything(), \INF)
                    ->willReturn('x' . $i);

                $mutex
                    ->method('releaseWithToken')
                    ->with(self::anything(), 'x' . $i)
                    ->willReturn(true);

                return $mutex;
            },
            range(0, $count - 1)
        );

        return $this->getMockBuilder(DistributedMutex::class)
            ->setConstructorArgs([$mutexes, $acquireTimeout, $expireTimeout])
            ->onlyMethods(['acquireMutex', 'releaseMutex'])
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
        $mutex = $this->createDistributedMutexMock($count);

        $i = 0;
        $mutex->expects(self::atMost((int) floor($count / 2) + $count - $available))
            ->method('acquireMutex')
            ->willReturnCallback(static function () use (&$i, $available) {
                if ($i++ < $available) {
                    return true;
                }

                throw new LockAcquireException();
            });

        $this->expectException(LockAcquireException::class);
        $this->expectExceptionCode(MutexException::CODE_REDLOCK_NOT_ENOUGH_SERVERS);
        $mutex->synchronized(static function () {
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
        $mutex = $this->createDistributedMutexMock($count);
        $mutex->expects(self::exactly($available))
            ->method('releaseMutex')
            ->willReturn(true);

        $i = 0;
        $mutex->expects(self::exactly($count))
            ->method('acquireMutex')
            ->willReturnCallback(static function () use (&$i, $available) {
                if ($i++ < $available) {
                    return true;
                }

                throw new LockAcquireException();
            });

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
        $mutex = $this->createDistributedMutexMock($count);

        $i = 0;
        $mutex->expects(self::any())
            ->method('acquireMutex')
            ->willReturnCallback(static function () use (&$i, $available) {
                return ++$i <= $available;
            });

        $this->expectException(LockAcquireTimeoutException::class);
        $this->expectExceptionMessage('Lock acquire timeout of 1.0 seconds has been exceeded');
        $mutex->synchronized(static function () {
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
        $mutex = $this->createDistributedMutexMock($count, $timeout, $timeout);
        $mutex->expects(self::exactly($count))
            ->method('releaseMutex')
            ->willReturn(true);

        $mutex->expects(self::exactly($count))
            ->method('acquireMutex')
            ->willReturnCallback(static function () use ($delay) {
                usleep((int) ($delay * 1e6));

                return true;
            });

        $this->expectException(LockAcquireTimeoutException::class);
        $this->expectExceptionMessage('Lock acquire timeout of ' . LockUtil::getInstance()->formatTimeout($timeout) . ' seconds has been exceeded');
        $mutex->synchronized(static function () {
            self::fail();
        });
    }

    /**
     * @return iterable<list<mixed>>
     */
    public static function provideAcquireTimeoutsCases(): iterable
    {
        yield [1, 1.2, 1.201];
        yield [2, 20.4, 10.201];
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
        $mutex = $this->createDistributedMutexMock($count);
        $mutex->expects(self::exactly($available))
            ->method('releaseMutex')
            ->willReturn(true);

        $i = 0;
        $mutex->expects(self::exactly($count))
            ->method('acquireMutex')
            ->willReturnCallback(static function () use (&$i, $available) {
                return ++$i <= $available;
            });

        $mutex->synchronized(static function () {});
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
        yield [5, 3];
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
        $mutex = $this->createDistributedMutexMock($count);
        $mutex->expects(self::exactly($count))
            ->method('acquireMutex')
            ->willReturn(true);

        $i = 0;
        $mutex->expects(self::exactly($count))
            ->method('releaseMutex')
            ->willReturnCallback(static function () use (&$i, $available) {
                if ($i++ < $available) {
                    return true;
                }

                throw new LockReleaseException();
            });

        $this->expectException(LockReleaseException::class);
        $mutex->synchronized(static function () {});
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
        $mutex = $this->createDistributedMutexMock($count);
        $mutex->expects(self::exactly($count))
            ->method('acquireMutex')
            ->willReturn(true);

        $i = 0;
        $mutex->expects(self::exactly($count))
            ->method('releaseMutex')
            ->willReturnCallback(static function () use (&$i, $available) {
                return ++$i <= $available;
            });

        $this->expectException(LockReleaseException::class);
        $mutex->synchronized(static function () {});
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
        yield [5, 2];
        yield [6, 2];
        yield [6, 3];
    }

    public function testAcquireMutexLogger(): void
    {
        $mutex = $this->createDistributedMutexMock(3);
        $logger = $this->createMock(LoggerInterface::class);
        $mutex->setLogger($logger);

        $mutex->expects(self::exactly(2))
            ->method('acquireMutex')
            ->with(self::isInstanceOf(AbstractSpinlockMutex::class), 'distributed', 1.0, \INF)
            ->willThrowException($this->createMock(/* PredisException::class */ LockAcquireException::class));

        $logger->expects(self::exactly(2))
            ->method('warning')
            ->with('Could not set {key} = {token} at server #{index}', self::anything());

        $this->expectException(LockAcquireException::class);
        $this->expectExceptionMessage('It is not possible to acquire a lock because at least half of the servers are not available');
        $mutex->synchronized(static function () {
            self::fail();
        });
    }

    public function testReleaseMutexLogger(): void
    {
        $mutex = $this->createDistributedMutexMock(3);
        $logger = $this->createMock(LoggerInterface::class);
        $mutex->setLogger($logger);

        $mutex->expects(self::exactly(3))
            ->method('acquireMutex')
            ->willReturn(true);

        $mutex->expects(self::exactly(3))
            ->method('releaseMutex')
            ->with(self::isInstanceOf(AbstractSpinlockMutex::class), 'distributed', \INF)
            ->willThrowException($this->createMock(/* PredisException::class */ LockReleaseException::class));

        $logger->expects(self::exactly(3))
            ->method('warning')
            ->with('Could not unset {key} = {token} at server #{index}', self::anything());

        $this->expectException(LockReleaseException::class);
        $mutex->synchronized(static function () {});
    }
}
