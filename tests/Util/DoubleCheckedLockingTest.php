<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Util;

use Malkusch\Lock\Mutex\Mutex;
use Malkusch\Lock\Util\DoubleCheckedLocking;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DoubleCheckedLockingTest extends TestCase
{
    /** @var Mutex&MockObject */
    private $mutex;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mutex = $this->createMock(Mutex::class);
    }

    public function testCheckFailsAcquiresNoLock(): void
    {
        $this->mutex->expects(self::never())->method('synchronized');

        $checkedLocking = new DoubleCheckedLocking($this->mutex, static function (): bool {
            return false;
        });

        $result = $checkedLocking->then(static function (): void {
            self::fail();
        });

        self::assertFalse($result); // @phpstan-ignore staticMethod.impossibleType
    }

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

            return 'foo';
        });

        self::assertSame(2, $check);

        self::assertSame('foo', $result);
    }

    /**
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

        self::assertFalse($result); // @phpstan-ignore staticMethod.impossibleType
    }

    /**
     * @return iterable<list<mixed>>
     */
    public static function provideCodeNotExecutedCases(): iterable
    {
        yield 'failFirstCheck' => [static function (): bool {
            return false;
        }];

        $checkCounter = 0;
        yield 'failSecondCheck' => [static function () use (&$checkCounter): bool {
            return $checkCounter++ === 0;
        }];
    }

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

        $executedCount = 0;
        $result = $checkedLocking->then(static function () use (&$executedCount) {
            ++$executedCount;

            return 'foo';
        });

        self::assertSame(1, $executedCount);
        self::assertSame('foo', $result);
    }
}
