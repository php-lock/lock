<?php

namespace malkusch\lock\util;

use malkusch\lock\mutex\Mutex;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DoubleCheckedLocking.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 * @see DoubleCheckedLocking
 */
class DoubleCheckedLockingTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject The Mutex mock.
     */
    private $mutex;
    
    /**
     * @var DoubleCheckedLocking The SUT.
     */
    private $checkedLocking;
    
    protected function setUp()
    {
        parent::setUp();
        
        $this->mutex          = $this->createMock(Mutex::class);
        $this->checkedLocking = new DoubleCheckedLocking($this->mutex);
    }

    /**
     * Tests that the lock will not be acquired for a failing test.
     *
     * @test
     */
    public function testCheckFailsAcquiresNoLock()
    {
        $this->mutex->expects($this->never())->method("synchronized");
        $this->checkedLocking->setCheck(function () {
            return false;
        });
        $result = $this->checkedLocking->then(function () {
            $this->fail();
        });

        // Failed check should return false.
        $this->assertFalse($result);
    }
    
    /**
     * Tests that the check and execution are in the same lock.
     *
     * @test
     */
    public function testLockedCheckAndExecution()
    {
        $lock  = 0;
        $check = 0;
        
        $this->mutex->expects($this->once())
                ->method("synchronized")
                ->willReturnCallback(function (callable $block) use (&$lock) {
                    $lock++;
                    $result = call_user_func($block);
                    $lock++;

                    return $result;
                });
        
        $this->checkedLocking->setCheck(function () use (&$lock, &$check) {
            if ($check == 1) {
                $this->assertEquals(1, $lock);
            }
            $check++;

            return true;
        });

        $result = $this->checkedLocking->then(function () use (&$lock) {
            $this->assertEquals(1, $lock);

            return 'test';
        });

        $this->assertEquals(2, $check);

        // Synchronized code should return a test string.
        $this->assertEquals('test', $result);
    }
    
    /**
     * Tests that the code is not executed if the first or second check fails.
     *
     * @param callable $check The check.
     * @test
     * @dataProvider provideTestCodeNotExecuted
     */
    public function testCodeNotExecuted(callable $check)
    {
        $this->mutex->expects($this->any())
                ->method("synchronized")
                ->willReturnCallback(function (callable $block) {
                    return call_user_func($block);
                });
                
        $this->checkedLocking->setCheck($check);
        $result = $this->checkedLocking->then(function () {
            $this->fail();
        });

        // Each failed check should return false.
        $this->assertFalse($result);
    }
    
    /**
     * Returns checks for testCodeNotExecuted().
     *
     * @return callable[][] The test cases.
     */
    public function provideTestCodeNotExecuted()
    {
        $checkCounter = 0;
        return [
            [function () {
                return false;
            }],

            [function () use (&$checkCounter) {
                $result = $checkCounter == 0;
                $checkCounter++;
                return $result;
            }],
        ];
    }
    
    /**
     * Tests that the code executed if the checks are true.
     *
     * @test
     */
    public function testCodeExecuted()
    {
        $this->mutex->expects($this->once())
                ->method("synchronized")
                ->willReturnCallback(function (callable $block) {
                    return call_user_func($block);
                });
                
        $this->checkedLocking->setCheck(function () {
            return true;
        });

        $executed = false;
        $result = $this->checkedLocking->then(function () use (&$executed) {
            $executed = true;

            return 'test';
        });

        $this->assertTrue($executed);
        $this->assertEquals('test', $result);
    }
}
