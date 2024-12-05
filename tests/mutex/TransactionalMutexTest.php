<?php

declare(strict_types=1);

namespace malkusch\lock\Tests\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\mutex\TransactionalMutex;
use PHPUnit\Framework\TestCase;

/**
 * Set the environment variables MYSQL_DSN, MYSQL_USER for this test.
 */
class TransactionalMutexTest extends TestCase
{
    /**
     * Tests building the mutex with an invalid error mode.
     *
     * @dataProvider provideInvalidErrorModeCases
     */
    public function testInvalidErrorMode(int $mode): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, $mode);
        new TransactionalMutex($pdo);
    }

    /**
     * Returns test cases for testInvalidErrorMode().
     *
     * @return iterable<list<mixed>>
     */
    public static function provideInvalidErrorModeCases(): iterable
    {
        return [
            [\PDO::ERRMODE_SILENT],
            [\PDO::ERRMODE_WARNING],
        ];
    }

    /**
     * Tests BEGIN fails.
     */
    public function testBeginFails(): void
    {
        $this->expectException(LockAcquireException::class);
        $this->expectExceptionMessage('Could not begin transaction');

        $pdo = $this->buildMySqlPdo();
        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        $stmt = $pdo->prepare('SELECT 1 FROM DUAL');
        $stmt->execute();

        $mutex = new TransactionalMutex($pdo);
        $mutex->synchronized(static function (): void {});
    }

    /**
     * Tests that an exception in the critical code causes a ROLLBACK.
     */
    public function testExceptionRollsback(): void
    {
        $pdo = $this->buildMySqlPdo();
        $mutex = new TransactionalMutex($pdo);

        $pdo->exec('
            CREATE TEMPORARY TABLE testExceptionRollsback(
                id int primary key
            ) engine=innodb
        ');

        try {
            $mutex->synchronized(static function () use ($pdo): void {
                $pdo->exec('INSERT INTO testExceptionRollsback VALUES(1)');

                throw new \DomainException();
            });
        } catch (\DomainException $e) {
            // expected
        }

        $count = $pdo->query('SELECT count(*) FROM testExceptionRollsback')->fetchColumn();
        self::assertSame(0, \PHP_VERSION_ID < 8_01_00 ? (int) $count : $count);
    }

    /**
     * Tests that a ROLLBACK caused by an exception fails.
     */
    public function testFailExceptionRollsback(): void
    {
        $pdo = $this->buildMySqlPdo();
        $mutex = new TransactionalMutex($pdo);

        $this->expectException(LockAcquireException::class);

        $mutex->synchronized(static function () use ($pdo) {
            // This will provoke the mutex' rollback to fail.
            $pdo->rollBack();

            throw new \DomainException();
        });
    }

    /**
     * Tests replaying the transaction.
     *
     * @dataProvider provideReplayTransactionCases
     */
    public function testReplayTransaction(\Exception $exception): void
    {
        $pdo = $this->buildMySqlPdo();
        $mutex = new TransactionalMutex($pdo);

        $pdo->exec('
            CREATE TEMPORARY TABLE testExceptionRollsback(
                id int primary key
            ) engine=innodb
        ');

        $i = 0;
        $mutex->synchronized(static function () use ($pdo, &$i, $exception) {
            ++$i;

            $count = $pdo->query('SELECT count(*) FROM testExceptionRollsback')->fetchColumn();
            self::assertSame(0, \PHP_VERSION_ID < 8_01_00 ? (int) $count : $count);

            $pdo->exec('INSERT INTO testExceptionRollsback VALUES(1)');

            // this provokes the replay
            if ($i < 5) {
                throw $exception;
            }
        });

        $count = $pdo->query('SELECT count(*) FROM testExceptionRollsback')->fetchColumn();
        self::assertSame(1, \PHP_VERSION_ID < 8_01_00 ? (int) $count : $count);

        self::assertSame(5, $i);
    }

    /**
     * Returns test cases for testReplayTransaction().
     *
     * @return iterable<list<mixed>>
     */
    public static function provideReplayTransactionCases(): iterable
    {
        return [
            [new \PDOException()],
            [new \Exception('', 0, new \PDOException())],
        ];
    }

    /**
     * Tests failing a ROLLBACK after the failed COMMIT.
     */
    public function testRollbackAfterFailedCommitFails(): void
    {
        $this->expectException(LockAcquireException::class);
        $this->expectExceptionMessage('Could not roll back transaction:');

        $pdo = $this->buildMySqlPdo();
        $mutex = new TransactionalMutex($pdo);

        $mutex->synchronized(static function () use ($pdo) {
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
     */
    private function buildMySqlPdo(): \PDO
    {
        if (!getenv('MYSQL_DSN')) {
            self::markTestSkipped();
        }

        $dsn = getenv('MYSQL_DSN');
        $user = getenv('MYSQL_USER');
        $password = getenv('MYSQL_PASSWORD');
        $pdo = new \PDO($dsn, $user, $password);

        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
