<?php

namespace malkusch\lock\mutex;

/**
 * Tests for TransactionalMutex.
 *
 * Set the environment variables MYSQL_DSN, MYSQL_USER for this test.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see TransactionalMutex
 */
class TransactionalMutexTest extends \PHPUnit_Framework_TestCase
{

    /**
     * Tests building the mutex with an invalid error mode.
     *
     * @param int $mode The invalid error mode.
     * @dataProvider provideTestInvalidErrorMode
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function testInvalidErrorMode($mode)
    {
        $pdo = new \PDO("sqlite::memory:");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, $mode);
        new TransactionalMutex($pdo);
    }
    
    /**
     * Returns test cases for testInvalidErrorMode().
     *
     * @return array Test cases.
     */
    public function provideTestInvalidErrorMode()
    {
        return [
            [\PDO::ERRMODE_SILENT],
            [\PDO::ERRMODE_WARNING],
        ];
    }

    /**
     * Tests BEGIN fails.
     *
     * @test
     * @expectedException \malkusch\lock\exception\LockAcquireException
     * @expectedExceptionMessage Could not begin transaction.
     */
    public function testBeginFails()
    {
        $pdo = $this->buildMySqlPdo();
        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        $stmt = $pdo->prepare("SELECT 1 FROM DUAL");
        $stmt->execute();
        
        $mutex = new TransactionalMutex($pdo);
        $mutex->synchronized(function () {
        });
    }

    /**
     * Tests that an exception in the critical code causes a ROLLBACK.
     *
     * @test
     */
    public function testExceptionRollsback()
    {
        $pdo   = $this->buildMySqlPdo();
        $mutex = new TransactionalMutex($pdo);
        
        $pdo->exec("
            CREATE TEMPORARY TABLE testExceptionRollsback(
                id int primary key
            ) engine=innodb
        ");
        
        try {
            $mutex->synchronized(function () use ($pdo) {
                $pdo->exec("INSERT INTO testExceptionRollsback VALUES(1)");
                throw new \DomainException();
            });
        } catch (\DomainException $e) {
            // expected
        }
        
        $count = $pdo->query("SELECT count(*) FROM testExceptionRollsback")->fetchColumn();
        $this->assertEquals(0, $count);
    }

    /**
     * Tests that a ROLLBACK caused by an exception fails.
     *
     * @test
     * @expectedException \malkusch\lock\exception\LockAcquireException
     */
    public function testFailExceptionRollsback()
    {
        $pdo   = $this->buildMySqlPdo();
        $mutex = new TransactionalMutex($pdo);
        
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
     * @test
     * @dataProvider provideTestReplayTransaction
     */
    public function testReplayTransaction(\Exception $exception)
    {
        $pdo   = $this->buildMySqlPdo();
        $mutex = new TransactionalMutex($pdo);
        
        $pdo->exec("
            CREATE TEMPORARY TABLE testExceptionRollsback(
                id int primary key
            ) engine=innodb
        ");
        
        $i = 0;
        $mutex->synchronized(function () use ($pdo, &$i, $exception) {
            $i++;

            $count = $pdo->query("SELECT count(*) FROM testExceptionRollsback")->fetchColumn();
            $this->assertEquals(0, $count);
            
            $pdo->exec("INSERT INTO testExceptionRollsback VALUES(1)");
            
            // this provokes the replay
            if ($i < 5) {
                throw $exception;
            }
        });

        $count = $pdo->query("SELECT count(*) FROM testExceptionRollsback")->fetchColumn();
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
            [new \Exception("", 0, new \PDOException())],
        ];
    }
    
    /**
     * Tests failing a ROLLBACK after the failed COMMIT.
     *
     * @test
     * @expectedException \malkusch\lock\exception\LockAcquireException
     * @expectedExceptionMessage Could not roll back transaction:
     */
    public function testRollbackAfterFailedCommitFails()
    {
        $pdo   = $this->buildMySqlPdo();
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
     *
     * @return \PDO The MySQL PDO.
     */
    private function buildMySqlPdo()
    {
        if (!getenv("MYSQL_DSN")) {
            $this->markTestSkipped();
        }
        
        $dsn  = getenv("MYSQL_DSN");
        $user = getenv("MYSQL_USER");
        $pdo = new \PDO($dsn, $user);
        
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        
        return $pdo;
    }
}
