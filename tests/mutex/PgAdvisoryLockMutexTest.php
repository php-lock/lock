<?php

declare(strict_types=1);

namespace malkusch\lock\Tests\mutex;

use malkusch\lock\mutex\PgAdvisoryLockMutex;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PgAdvisoryLockMutexTest extends TestCase
{
    /** @var \PDO&MockObject */
    private $pdo;

    /** @var PgAdvisoryLockMutex */
    private $mutex;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = $this->createMock(\PDO::class);

        $this->mutex = new PgAdvisoryLockMutex($this->pdo, 'test');
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
                self::callback(function (...$arguments): bool {
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
                [-848589047, 1943216454]
            ));

        \Closure::bind(static fn ($mutex) => $mutex->lock(), null, PgAdvisoryLockMutex::class)($this->mutex);
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
                self::callback(function (...$arguments): bool {
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
                [-848589047, 1943216454]
            ));

        \Closure::bind(static fn ($mutex) => $mutex->unlock(), null, PgAdvisoryLockMutex::class)($this->mutex);
    }
}
