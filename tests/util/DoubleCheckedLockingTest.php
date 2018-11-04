<?php

namespace malkusch\lock\util;

use malkusch\lock\mutex\Mutex;
use PHPUnit\Framework\MockObject\MockObject;
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
     * @var Mutex|MockObject The Mutex mock.
     */
    private $mutex;
    
    protected function setUp()
    {
        parent::setUp();
        
        $this->mutex = $this->createMock(Mutex::class);
    }

    /**
     * Tests that the lock will not be acquired for a failing test.
     */
    public function testCheckFailsAcquiresNoLock()
    {
        $this->mutex->expects($this->never())->method("synchronized");

        $checkedLocking = new DoubleCheckedLocking($this->mutex, function (): bool {
            return false;
        });

        $result = $checkedLocking->then(function (): void {
            $this->fail();
        });

        // Failed check should return false.
        $this->assertFalse($result);
    }
    
    /**
     * Tests that the check and execution are in the same lock.
     *
     */
    public function testLockedCheckAndExecution()
    {
        $lock  = 0;
        $check = 0;
        
        $this->mutex->expects($this->once())
                ->method("synchronized")
                ->willReturnCallback(function (callable $block) use (&$lock) {
                    $lock++;
                    $result = $block();
                    $lock++;

                    return $result;
                });

        $checkedLocking = new DoubleCheckedLocking($this->mutex, function () use (&$lock, &$check): bool {
            if ($check == 1) {
                $this->assertSame(1, $lock);
            }
            $check++;

            return true;
        });

        $result = $checkedLocking->then(function () use (&$lock) {
            $this->assertSame(1, $lock);

            return 'test';
        });

        $this->assertSame(2, $check);

        // Synchronized code should return a test string.
        $this->assertSame('test', $result);
    }
    
    /**
     * Tests that the code is not executed if the first or second check fails.
     *
     * @param callable $check The check.
     * @dataProvider provideTestCodeNotExecuted
     */
    public function testCodeNotExecuted(callable $check)
    {
        $this->mutex->expects($this->any())
                ->method("synchronized")
                ->willReturnCallback(function (callable $block) {
                    return $block();
                });

        $checkedLocking = new DoubleCheckedLocking($this->mutex, $check);
        $result = $checkedLocking->then(function (): void {
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
            [function (): bool {
                return false;
            }],

            [function () use (&$checkCounter): bool {
                $result = $checkCounter == 0;
                $checkCounter++;
                return $result;
            }],
        ];
    }
    
    /**
     * Tests that the code executed if the checks are true.
     */
    public function testCodeExecuted()
    {
        $this->mutex->expects($this->once())
                ->method("synchronized")
                ->willReturnCallback(function (callable $block) {
                    return $block();
                });

        $checkedLocking = new DoubleCheckedLocking($this->mutex, function (): bool {
            return true;
        });

        $executed = false;
        $result = $checkedLocking->then(function () use (&$executed) {
            $executed = true;

            return 'test';
        });

        $this->assertTrue($executed);
        $this->assertEquals('test', $result);
    }
}
