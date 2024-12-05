<?php

declare(strict_types=1);

namespace malkusch\lock\Tests\mutex;

use malkusch\lock\mutex\PgAdvisoryLockMutex;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PgAdvisoryLockMutexTest extends TestCase
{
    /** @var \PDO|MockObject */
    private $pdo;

    /** @var PgAdvisoryLockMutex */
    private $mutex;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = $this->createMock(\PDO::class);

        $this->mutex = new PgAdvisoryLockMutex($this->pdo, 'test' . uniqid());
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
            ->with('SELECT pg_advisory_lock(?,?)')
            ->willReturn($statement);

        $statement->expects(self::once())
            ->method('execute')
            ->with(
                self::logicalAnd(
                    self::isType('array'),
                    self::countOf(2),
                    self::callback(function (...$arguments): bool {
                        if ($this->isPhpunit9x()) { // https://github.com/sebastianbergmann/phpunit/issues/5891
                            $arguments = $arguments[0];
                        }

                        foreach ($arguments as $v) {
                            self::assertIsInt($v);
                        }

                        return true;
                    })
                )
            );

        $this->mutex->lock();
    }

    public function testReleaseLock(): void
    {
        $statement = $this->createMock(\PDOStatement::class);

        $this->pdo->expects(self::once())
            ->method('prepare')
            ->with('SELECT pg_advisory_unlock(?,?)')
            ->willReturn($statement);

        $statement->expects(self::once())
            ->method('execute')
            ->with(
                self::logicalAnd(
                    self::isType('array'),
                    self::countOf(2),
                    self::callback(function (...$arguments): bool {
                        if ($this->isPhpunit9x()) { // https://github.com/sebastianbergmann/phpunit/issues/5891
                            $arguments = $arguments[0];
                        }

                        foreach ($arguments as $v) {
                            self::assertLessThan(1 << 32, $v);
                            self::assertGreaterThan(-(1 << 32), $v);
                            self::assertIsInt($v);
                        }

                        return true;
                    })
                )
            );

        $this->mutex->unlock();
    }
}
