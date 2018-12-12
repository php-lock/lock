<?php

namespace malkusch\lock\mutex;

use PHPUnit\Framework\TestCase;

/**
 * @author Willem Stuursma-Ruwen <willem@stuursma.name>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 */
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

    protected function setUp()
    {
        parent::setUp();

        $this->pdo = $this->createMock(\PDO::class);

        $this->mutex = new PgAdvisoryLockMutex($this->pdo, 'test' . uniqid());
    }

    public function testAcquireLock()
    {
        $statement = $this->createMock(\PDOStatement::class);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with('SELECT pg_advisory_lock(?,?)')
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->isType('array'),
                    $this->countOf(2),
                    $this->callback(function (...$arguments) {
                        $integers = $arguments[0];

                        foreach ($integers as $each) {
                            $this->assertInternalType('integer', $each);
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

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with('SELECT pg_advisory_unlock(?,?)')
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->isType('array'),
                    $this->countOf(2),
                    $this->callback(function (...$arguments): bool {
                        $integers = $arguments[0];

                        foreach ($integers as $each) {
                            $this->assertLessThan(1 << 32, $each);
                            $this->assertGreaterThan(-(1 << 32), $each);
                            $this->assertInternalType('integer', $each);
                        }

                        return true;
                    })
                )
            );

        $this->mutex->unlock();
    }
}
