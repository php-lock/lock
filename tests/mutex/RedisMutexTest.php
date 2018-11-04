<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RedisMutex.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 *
 * @see RedisMutex
 * @group redis
 */
class RedisMutexTest extends TestCase
{
    use PHPMock;
    
    protected function setUp()
    {
        parent::setUp();
        
        $sleepBuilder = new SleepEnvironmentBuilder();
        $sleepBuilder->addNamespace(__NAMESPACE__);
        $sleepBuilder->addNamespace('malkusch\lock\util');
        $sleep = $sleepBuilder->build();

        $sleep->enable();
        $this->registerForTearDown($sleep);
    }

    /**
     * Builds a testable RedisMutex mock.
     *
     * @param int $count The amount of redis apis.
     * @param int $timeout The timeout.
     *
     * @return \PHPUnit\Framework\MockObject\MockObject|RedisMutex
     */
    private function buildRedisMutex(int $count, int $timeout = 1)
    {
        $redisAPIs = array_map(
            function ($id): array {
                return ["id" => $id];
            },
            range(1, $count)
        );

        return $this->getMockForAbstractClass(RedisMutex::class, [$redisAPIs, "test", $timeout]);
    }
    
    /**
     * Tests acquire() fails because too few servers are available.
     *
     * @param int $count The total count of servers
     * @param int $available The count of available servers.
     *
     * @expectedException \malkusch\lock\exception\LockAcquireException
     * @expectedExceptionCode \malkusch\lock\exception\MutexException::REDIS_NOT_ENOUGH_SERVERS
     * @dataProvider provideMinority
     */
    public function testTooFewServerToAcquire(int $count, int $available)
    {
        $mutex = $this->buildRedisMutex($count);
        
        $i = 0;
        $mutex->expects($this->exactly($count))
            ->method("add")
            ->willReturnCallback(
                function () use (&$i, $available): bool {
                    if ($i < $available) {
                        $i++;
                        return true;
                    } else {
                        throw new LockAcquireException();
                    }
                }
            );
        
        $mutex->synchronized(function (): void {
            $this->fail("Code should not be executed");
        });
    }
    
    /**
     * Tests synchronized() does work if the majority of servers is up.
     *
     * @param int $count The total count of servers
     * @param int $available The count of available servers.
     *
     * @dataProvider provideMajority
     */
    public function testFaultTolerance(int $count, int $available)
    {
        $mutex = $this->buildRedisMutex($count);
        $mutex->expects($this->exactly($count))
            ->method("evalScript")
            ->willReturn(true);
        
        $i = 0;
        $mutex->expects($this->exactly($count))
            ->method("add")
            ->willReturnCallback(
                function () use (&$i, $available): bool {
                    if ($i < $available) {
                        $i++;
                        return true;
                    } else {
                        throw new LockAcquireException();
                    }
                }
            );
        
        $mutex->synchronized(function () {
        });
    }

    /**
     * Tests too few keys could be acquired.
     *
     * @param int $count The total count of servers
     * @param int $available The count of available servers.
     *
     * @expectedException \malkusch\lock\exception\TimeoutException
     * @expectedExceptionMessage Timeout of 1 seconds exceeded.
     * @dataProvider provideMinority
     */
    public function testAcquireTooFewKeys($count, $available)
    {
        $mutex = $this->buildRedisMutex($count);
        
        $i = 0;
        $mutex->expects($this->any())
            ->method("add")
            ->willReturnCallback(
                function () use (&$i, $available): bool {
                    $i++;
                    return $i <= $available;
                }
            );
        
        $mutex->synchronized(function (): void {
            $this->fail("Code should not be executed");
        });
    }

    /**
     * Tests acquiring keys takes too long.
     *
     * @param int $count The total count of servers.
     * @param int $timeout The timeout in seconds.
     * @param int $delay The delay in microseconds.
     *
     * @expectedException \malkusch\lock\exception\TimeoutException
     * @dataProvider provideTestTimingOut
     */
    public function testTimingOut(int $count, int $timeout, int $delay)
    {
        $mutex = $this->buildRedisMutex($count, $timeout);
        
        $mutex->expects($this->exactly($count))
            ->method("add")
            ->willReturnCallback(function () use ($delay): bool {
                usleep($delay);
                return true;
            });
        
        $mutex->synchronized(function () {
            $this->fail("Code should not be executed");
        });
    }

    /**
     * Returns test cases for testTimingOut().
     *
     * @return array Test cases.
     */
    public function provideTestTimingOut()
    {
        // count, timeout, delay
        return [
            [1, 1, 2001000],
            [2, 1, 1001000],
        ];
    }
    
    /**
     * Tests synchronized() works if the majority of keys was acquired.
     *
     * @param int $count The total count of servers
     * @param int $available The count of available servers.
     *
     * @dataProvider provideMajority
     */
    public function testAcquireWithMajority(int $count, int $available)
    {
        $mutex = $this->buildRedisMutex($count);
        $mutex->expects($this->exactly($count))
            ->method("evalScript")
            ->willReturn(true);
        
        $i = 0;
        $mutex->expects($this->exactly($count))
            ->method("add")
            ->willReturnCallback(
                function () use (&$i, $available): int {
                    $i++;
                    return $i <= $available;
                }
            );
        
        $mutex->synchronized(function (): void {
        });
    }

    /**
     * Tests releasing fails because too few servers are available.
     *
     * @param int $count The total count of servers
     * @param int $available The count of available servers.
     *
     * @expectedException \malkusch\lock\exception\LockReleaseException
     * @dataProvider provideMinority
     */
    public function testTooFewServersToRelease(int $count, int $available)
    {
        $mutex = $this->buildRedisMutex($count);
        $mutex->expects($this->exactly($count))
            ->method("add")
            ->willReturn(true);
        
        $i = 0;
        $mutex->expects($this->exactly($count))
            ->method("evalScript")
            ->willReturnCallback(
                function () use (&$i, $available): bool {
                    if ($i < $available) {
                        $i++;
                        return true;
                    } else {
                        throw new LockReleaseException();
                    }
                }
            );
        
        $mutex->synchronized(function (): void {
        });
    }

    /**
     * Tests releasing too few keys.
     *
     * @param int $count The total count of servers
     * @param int $available The count of available servers.
     *
     * @expectedException \malkusch\lock\exception\LockReleaseException
     * @dataProvider provideMinority
     */
    public function testReleaseTooFewKeys($count, $available)
    {
        $mutex = $this->buildRedisMutex($count);
        $mutex->expects($this->exactly($count))
            ->method("add")
            ->willReturn(true);
        
        $i = 0;
        $mutex->expects($this->exactly($count))
            ->method("evalScript")
            ->willReturnCallback(
                function () use (&$i, $available): bool {
                    $i++;
                    return $i <= $available;
                }
            );
        
        $mutex->synchronized(function (): void {
        });
    }
    
    /**
     * Provides test cases with too few.
     *
     * @return int[][] Test cases.
     */
    public function provideMinority()
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
     * @return int[][] Test cases.
     */
    public function provideMajority()
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
