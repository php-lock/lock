<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TransactionalMutex.
 *
 * Set the environment variables MYSQL_DSN, MYSQL_USER for this test.
 */
class TransactionalMutexTest extends TestCase
{
    /**
     * Tests building the mutex with an invalid error mode.
     *
     * @param int $mode The invalid error mode.
     * @dataProvider provideTestInvalidErrorMode
     */
    public function testInvalidErrorMode(int $mode)
    {
        $this->expectException(\InvalidArgumentException::class);

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, $mode);
        new TransactionalMutex($pdo);
    }

    /**
     * Returns test cases for testInvalidErrorMode().
     */
    public function provideTestInvalidErrorMode(): array
    {
        return [
            [\PDO::ERRMODE_SILENT],
            [\PDO::ERRMODE_WARNING],
        ];
    }

    /**
     * Tests BEGIN fails.
     */
    public function testBeginFails()
    {
        $this->expectException(LockAcquireException::class);
        $this->expectExceptionMessage('Could not begin transaction.');

        $pdo = $this->buildMySqlPdo();
        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        $stmt = $pdo->prepare('SELECT 1 FROM DUAL');
        $stmt->execute();

        $mutex = new TransactionalMutex($pdo);
        $mutex->synchronized(function (): void {
        });
    }

    /**
     * Tests that an exception in the critical code causes a ROLLBACK.
     */
    public function testExceptionRollsback()
    {
        $pdo = $this->buildMySqlPdo();
        $mutex = new TransactionalMutex($pdo);

        $pdo->exec('
            CREATE TEMPORARY TABLE testExceptionRollsback(
                id int primary key
            ) engine=innodb
        ');

        try {
            $mutex->synchronized(function () use ($pdo): void {
                $pdo->exec('INSERT INTO testExceptionRollsback VALUES(1)');
                throw new \DomainException();
            });
        } catch (\DomainException $e) {
            // expected
        }

        $count = $pdo->query('SELECT count(*) FROM testExceptionRollsback')->fetchColumn();
        $this->assertEquals(0, $count);
    }

    /**
     * Tests that a ROLLBACK caused by an exception fails.
     */
    public function testFailExceptionRollsback()
    {
        $pdo = $this->buildMySqlPdo();
        $mutex = new TransactionalMutex($pdo);

        $this->expectException(LockAcquireException::class);

        $mutex->synchronized(function () use ($pdo) {
            // This will provoke the mutex' rollback to fail.
            $pdo->rollBack();

            throw new \DomainException();
        });
    }

    /**
     * Tests replaying the transaction.
     *
     * @param \Exception $exception The thrown exception.
     * @dataProvider provideTestReplayTransaction
     */
    public function testReplayTransaction(\Exception $exception)
    {
        $pdo = $this->buildMySqlPdo();
        $mutex = new TransactionalMutex($pdo);

        $pdo->exec('
            CREATE TEMPORARY TABLE testExceptionRollsback(
                id int primary key
            ) engine=innodb
        ');

        $i = 0;
        $mutex->synchronized(function () use ($pdo, &$i, $exception) {
            $i++;

            $count = $pdo->query('SELECT count(*) FROM testExceptionRollsback')->fetchColumn();
            $this->assertEquals(0, $count);

            $pdo->exec('INSERT INTO testExceptionRollsback VALUES(1)');

            // this provokes the replay
            if ($i < 5) {
                throw $exception;
            }
        });

        $count = $pdo->query('SELECT count(*) FROM testExceptionRollsback')->fetchColumn();
        $this->assertEquals(1, $count);

        $this->assertEquals(5, $i);
    }

    /**
     * Returns test cases for testReplayTransaction().
     *
     * @return \Exception[][] Test cases.
     */
    public function provideTestReplayTransaction()
    {
        return [
            [new \PDOException()],
            [new \Exception('', 0, new \PDOException())],
        ];
    }

    /**
     * Tests failing a ROLLBACK after the failed COMMIT.
     */
    public function testRollbackAfterFailedCommitFails()
    {
        $this->expectException(LockAcquireException::class);
        $this->expectExceptionMessage('Could not roll back transaction:');

        $pdo = $this->buildMySqlPdo();
        $mutex = new TransactionalMutex($pdo);

        $mutex->synchronized(function () use ($pdo) {
            // This will provoke the mutex' commit and rollback to fail.
            $pdo->rollBack();
        });
    }

    /**
     * Builds a MySQL PDO.
     *
     * Please provide these environment variables:
     *
     * - MYSQL_DSN
     * - MYSQL_USER
     * - MYSQL_PASSWORD
     *
     * @return \PDO The MySQL PDO.
     */
    private function buildMySqlPdo()
    {
        if (!getenv('MYSQL_DSN')) {
            $this->markTestSkipped();
        }

        $dsn = getenv('MYSQL_DSN');
        $user = getenv('MYSQL_USER');
        $password = getenv('MYSQL_PASSWORD');
        $pdo = new \PDO($dsn, $user, $password);

        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
