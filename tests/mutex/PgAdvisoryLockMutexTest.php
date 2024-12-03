<?php

namespace malkusch\lock\mutex;

use PHPUnit\Framework\TestCase;

class PgAdvisoryLockMutexTest extends TestCase
{
    /**
     * @var \PDO|\PHPUnit\Framework\MockObject\MockObject
     */
    private $pdo;

    /**
     * @var PgAdvisoryLockMutex
     */
    private $mutex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = $this->createMock(\PDO::class);

        $this->mutex = new PgAdvisoryLockMutex($this->pdo, 'test' . uniqid());
    }

    public function testAcquireLock()
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
                        $integers = $arguments[0];

                        foreach ($integers as $each) {
                            self::assertIsInt($each);
                        }

                        return true;
                    })
                )
            );

        $this->mutex->lock();
    }

    public function testReleaseLock()
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
                        $integers = $arguments[0];

                        foreach ($integers as $each) {
                            self::assertLessThan(1 << 32, $each);
                            self::assertGreaterThan(-(1 << 32), $each);
                            self::assertIsInt($each);
                        }

                        return true;
                    })
                )
            );

        $this->mutex->unlock();
    }
}
