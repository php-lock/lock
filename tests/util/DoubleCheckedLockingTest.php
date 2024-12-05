<?php

declare(strict_types=1);

namespace malkusch\lock\Tests\util;

use malkusch\lock\mutex\Mutex;
use malkusch\lock\util\DoubleCheckedLocking;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DoubleCheckedLockingTest extends TestCase
{
    /** @var Mutex|MockObject */
    private $mutex;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mutex = $this->createMock(Mutex::class);
    }

    /**
     * Tests that the lock will not be acquired for a failing test.
     */
    public function testCheckFailsAcquiresNoLock(): void
    {
        $this->mutex->expects(self::never())->method('synchronized');

        $checkedLocking = new DoubleCheckedLocking($this->mutex, static function (): bool {
            return false;
        });

        $result = $checkedLocking->then(static function (): void {
            self::fail();
        });

        // Failed check should return false.
        self::assertFalse($result); // @phpstan-ignore staticMethod.impossibleType
    }

    /**
     * Tests that the check and execution are in the same lock.
     */
    public function testLockedCheckAndExecution(): void
    {
        $lock = 0;
        $check = 0;

        $this->mutex->expects(self::once())
            ->method('synchronized')
            ->willReturnCallback(static function (\Closure $block) use (&$lock) {
                ++$lock;
                $result = $block();
                ++$lock;

                return $result;
            });

        $checkedLocking = new DoubleCheckedLocking($this->mutex, static function () use (&$lock, &$check): bool {
            if ($check === 1) {
                self::assertSame(1, $lock);
            }
            ++$check;

            return true;
        });

        $result = $checkedLocking->then(static function () use (&$lock) {
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
     * @param \Closure(): bool $check
     *
     * @dataProvider provideCodeNotExecutedCases
     */
    #[DataProvider('provideCodeNotExecutedCases')]
    public function testCodeNotExecuted(\Closure $check): void
    {
        $this->mutex->expects(self::any())
            ->method('synchronized')
            ->willReturnCallback(static function (\Closure $block) {
                return $block();
            });

        $checkedLocking = new DoubleCheckedLocking($this->mutex, $check);
        $result = $checkedLocking->then(static function (): void {
            self::fail();
        });

        // Each failed check should return false.
        self::assertFalse($result); // @phpstan-ignore staticMethod.impossibleType
    }

    /**
     * Returns checks for testCodeNotExecuted().
     *
     * @return iterable<list<mixed>>
     */
    public static function provideCodeNotExecutedCases(): iterable
    {
        $checkCounter = 0;

        return [
            [static function (): bool {
                return false;
            }],

            [static function () use (&$checkCounter): bool {
                $result = $checkCounter === 0;
                ++$checkCounter;

                return $result;
            }],
        ];
    }

    /**
     * Tests that the code executed if the checks are true.
     */
    public function testCodeExecuted(): void
    {
        $this->mutex->expects(self::once())
            ->method('synchronized')
            ->willReturnCallback(static function (\Closure $block) {
                return $block();
            });

        $checkedLocking = new DoubleCheckedLocking($this->mutex, static function (): bool {
            return true;
        });

        $executed = false;
        $result = $checkedLocking->then(static function () use (&$executed) {
            $executed = true;

            return 'test';
        });

        self::assertTrue($executed);
        self::assertSame('test', $result);
    }
}
