<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\phpunit\PHPMock;

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
class RedisMutexTest extends \PHPUnit_Framework_TestCase
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
     * @return \PHPUnit_Framework_MockObject_MockObject|RedisMutex
     */
    private function buildRedisMutex($count, $timeout = 1)
    {
        $redisAPIs = array_map(
            function ($id) {
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
     * @test
     * @expectedException \malkusch\lock\exception\LockAcquireException
     * @expectedExceptionCode \malkusch\lock\exception\MutexException::REDIS_NOT_ENOUGH_SERVERS
     * @dataProvider provideMinority
     */
    public function testTooFewServerToAcquire($count, $available)
    {
        $mutex = $this->buildRedisMutex($count);
        
        $i = 0;
        $mutex->expects($this->any())->method("add")->willReturnCallback(
            function () use (&$i, $available) {
                if ($i < $available) {
                    $i++;
                    return true;
                } else {
                    throw new LockAcquireException();
                }
            }
        );
        
        $mutex->synchronized(function () {
            $this->fail("Code should not be executed");
        });
    }
    
    /**
     * Tests synchronized() does work if the majority of servers is up.
     *
     * @param int $count The total count of servers
     * @param int $available The count of available servers.
     *
     * @test
     * @dataProvider provideMajority
     */
    public function testFaultTolerance($count, $available)
    {
        $mutex = $this->buildRedisMutex($count);
        $mutex->expects($this->any())->method("evalScript")->willReturn(true);
        
        $i = 0;
        $mutex->expects($this->any())->method("add")->willReturnCallback(
            function () use (&$i, $available) {
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
     * @test
     * @expectedException \malkusch\lock\exception\TimeoutException
     * @dataProvider provideMinority
     */
    public function testAcquireTooFewKeys($count, $available)
    {
        $mutex = $this->buildRedisMutex($count);
        
        $i = 0;
        $mutex->expects($this->any())->method("add")->willReturnCallback(
            function () use (&$i, $available) {
                $i++;
                return $i <= $available;
            }
        );
        
        $mutex->synchronized(function () {
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
     * @test
     * @expectedException \malkusch\lock\exception\TimeoutException
     * @dataProvider provideTestTimingOut
     */
    public function testTimingOut($count, $timeout, $delay)
    {
        $mutex = $this->buildRedisMutex($count, $timeout);
        
        $mutex->expects($this->any())->method("add")->willReturnCallback(function () use ($delay) {
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
     * @test
     * @dataProvider provideMajority
     */
    public function testAcquireWithMajority($count, $available)
    {
        $mutex = $this->buildRedisMutex($count);
        $mutex->expects($this->any())->method("evalScript")->willReturn(true);
        
        $i = 0;
        $mutex->expects($this->any())->method("add")->willReturnCallback(
            function () use (&$i, $available) {
                $i++;
                return $i <= $available;
            }
        );
        
        $mutex->synchronized(function () {
        });
    }

    /**
     * Tests releasing fails because too few servers are available.
     *
     * @param int $count The total count of servers
     * @param int $available The count of available servers.
     *
     * @test
     * @expectedException \malkusch\lock\exception\LockReleaseException
     * @dataProvider provideMinority
     */
    public function testTooFewServersToRelease($count, $available)
    {
        $mutex = $this->buildRedisMutex($count);
        $mutex->expects($this->any())->method("add")->willReturn(true);
        
        $i = 0;
        $mutex->expects($this->any())->method("evalScript")->willReturnCallback(
            function () use (&$i, $available) {
                if ($i < $available) {
                    $i++;
                    return true;
                } else {
                    throw new LockReleaseException();
                }
            }
        );
        
        $mutex->synchronized(function () {
        });
    }

    /**
     * Tests releasing too few keys.
     *
     * @param int $count The total count of servers
     * @param int $available The count of available servers.
     *
     * @test
     * @expectedException \malkusch\lock\exception\LockReleaseException
     * @dataProvider provideMinority
     */
    public function testReleaseTooFewKeys($count, $available)
    {
        $mutex = $this->buildRedisMutex($count);
        $mutex->expects($this->any())->method("add")->willReturn(true);
        
        $i = 0;
        $mutex->expects($this->any())->method("evalScript")->willReturnCallback(
            function () use (&$i, $available) {
                $i++;
                return $i <= $available;
            }
        );
        
        $mutex->synchronized(function () {
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
        ];
    }
}
