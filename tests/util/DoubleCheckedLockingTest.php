<?php

namespace malkusch\lock\util;

use malkusch\lock\mutex\Mutex;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DoubleCheckedLocking.
 */
class DoubleCheckedLockingTest extends TestCase
{
    /**
     * @var Mutex|MockObject the Mutex mock
     */
    private $mutex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mutex = $this->createMock(Mutex::class);
    }

    /**
     * Tests that the lock will not be acquired for a failing test.
     */
    public function testCheckFailsAcquiresNoLock()
    {
        $this->mutex->expects(self::never())->method('synchronized');

        $checkedLocking = new DoubleCheckedLocking($this->mutex, function (): bool {
            return false;
        });

        $result = $checkedLocking->then(function (): void {
            self::fail();
        });

        // Failed check should return false.
        self::assertFalse($result);
    }

    /**
     * Tests that the check and execution are in the same lock.
     */
    public function testLockedCheckAndExecution()
    {
        $lock = 0;
        $check = 0;

        $this->mutex->expects(self::once())
            ->method('synchronized')
            ->willReturnCallback(function (callable $block) use (&$lock) {
                $lock++;
                $result = $block();
                $lock++;

                return $result;
            });

        $checkedLocking = new DoubleCheckedLocking($this->mutex, function () use (&$lock, &$check): bool {
            if ($check == 1) {
                self::assertSame(1, $lock);
            }
            $check++;

            return true;
        });

        $result = $checkedLocking->then(function () use (&$lock) {
            self::assertSame(1, $lock);

            return 'test';
        });

        self::assertSame(2, $check);

        // Synchronized code should return a test string.
        self::assertSame('test', $result);
    }

    /**
     * Tests that the code is not executed if the first or second check fails.
     *
     * @param callable $check the check
     *
     * @dataProvider provideCodeNotExecutedCases
     */
    public function testCodeNotExecuted(callable $check)
    {
        $this->mutex->expects(self::any())
            ->method('synchronized')
            ->willReturnCallback(function (callable $block) {
                return $block();
            });

        $checkedLocking = new DoubleCheckedLocking($this->mutex, $check);
        $result = $checkedLocking->then(function (): void {
            self::fail();
        });

        // Each failed check should return false.
        self::assertFalse($result);
    }

    /**
     * Returns checks for testCodeNotExecuted().
     *
     * @return callable[][] the test cases
     */
    public static function provideCodeNotExecutedCases(): iterable
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
        $this->mutex->expects(self::once())
            ->method('synchronized')
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

        self::assertTrue($executed);
        self::assertEquals('test', $result);
    }
}
