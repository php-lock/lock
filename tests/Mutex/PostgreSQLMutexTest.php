<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Malkusch\Lock\Mutex\PostgreSQLMutex;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PostgreSQLMutexTest extends TestCase
{
    /** @var \PDO&MockObject */
    private $pdo;

    /** @var PostgreSQLMutex */
    private $mutex;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = $this->createMock(\PDO::class);

        $this->mutex = new PostgreSQLMutex($this->pdo, 'test-one-negative-key');
    }

    private function isPhpunit9x(): bool
    {
        return (new \ReflectionClass(self::class))->hasMethod('getStatus');
    }

    public function testAcquireLock(): void
    {
        $statement = $this->createMock(\PDOStatement::class);

        $this->pdo->expects(self::once())
            ->method('prepare')
            ->with('SELECT pg_advisory_lock(?, ?)')
            ->willReturn($statement);

        $statement->expects(self::once())
            ->method('execute')
            ->with(self::logicalAnd(
                new IsType(IsType::TYPE_ARRAY),
                self::countOf(2),
                self::callback(function (...$arguments) {
                    if ($this->isPhpunit9x()) { // https://github.com/sebastianbergmann/phpunit/issues/5891
                        $arguments = $arguments[0];
                    }

                    foreach ($arguments as $v) {
                        self::assertLessThan(1 << 32, $v);
                        self::assertGreaterThanOrEqual(-(1 << 32), $v);
                        self::assertIsInt($v);
                    }

                    return true;
                }),
                [533558444, -1716795572]
            ));

        \Closure::bind(static fn ($mutex) => $mutex->lock(), null, PostgreSQLMutex::class)($this->mutex);
    }

    public function testReleaseLock(): void
    {
        $statement = $this->createMock(\PDOStatement::class);

        $this->pdo->expects(self::once())
            ->method('prepare')
            ->with('SELECT pg_advisory_unlock(?, ?)')
            ->willReturn($statement);

        $statement->expects(self::once())
            ->method('execute')
            ->with(self::logicalAnd(
                new IsType(IsType::TYPE_ARRAY),
                self::countOf(2),
                self::callback(function (...$arguments) {
                    if ($this->isPhpunit9x()) { // https://github.com/sebastianbergmann/phpunit/issues/5891
                        $arguments = $arguments[0];
                    }

                    foreach ($arguments as $v) {
                        self::assertLessThan(1 << 32, $v);
                        self::assertGreaterThanOrEqual(-(1 << 32), $v);
                        self::assertIsInt($v);
                    }

                    return true;
                }),
                [533558444, -1716795572]
            ));

        \Closure::bind(static fn ($mutex) => $mutex->unlock(), null, PostgreSQLMutex::class)($this->mutex);
    }

    public function testAcquireTimeoutOccurs(): void
    {
        $statement = $this->createMock(\PDOStatement::class);

        $this->pdo->expects(self::atLeastOnce())
            ->method('prepare')
            ->with('SELECT pg_try_advisory_lock(?, ?)')
            ->willReturn($statement);

        $statement->expects(self::atLeastOnce())
            ->method('execute')
            ->with(self::logicalAnd(
                new IsType(IsType::TYPE_ARRAY),
                self::countOf(2),
                self::callback(function (...$arguments) {
                    if ($this->isPhpunit9x()) { // https://github.com/sebastianbergmann/phpunit/issues/5891
                        $arguments = $arguments[0];
                    }

                    foreach ($arguments as $v) {
                        self::assertLessThan(1 << 32, $v);
                        self::assertGreaterThanOrEqual(-(1 << 32), $v);
                        self::assertIsInt($v);
                    }

                    return true;
                }),
                [533558444, -1716795572]
            ));

        $statement->expects(self::atLeastOnce())
            ->method('fetchColumn')
            ->willReturn(false);

        \Closure::bind(static function (PostgreSQLMutex $mutex): void {
            $mutex->acquireTimeout = 1.0;
        }, null, PostgreSQLMutex::class)($this->mutex);

        $this->expectException(LockAcquireTimeoutException::class);
        $this->expectExceptionMessage('Lock acquire timeout of 1.0 seconds has been exceeded');
        \Closure::bind(static function (PostgreSQLMutex $mutex): void {
            $mutex->lock();
        }, null, PostgreSQLMutex::class)($this->mutex);
    }
}
