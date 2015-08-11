<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\util\Loop;

/**
 * Serialization is delegated to the DBS.
 *
 * The critical code is executed within a transaction. The DBS will decide
 * which parts of that code need to be locked (if at all).
 *
 * A failing transaction will be replayed.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 */
class TransactionalMutex extends Mutex
{
    
    /**
     * @var \PDO $pdo The PDO.
     */
    private $pdo;
    
    /**
     * @var Loop The loop.
     */
    private $loop;

    /**
     * Sets the PDO.
     *
     * @param \PDO $pdo     The PDO.
     * @param int  $timeout The timeout in seconds, default is 3.
     *
     * @throws \InvalidArgumentException PDO must be configured to throw exceptions and AUTOCOMMIT should be disabled.
     * @throws \LengthException The timeout must be greater than 0.
     */
    public function __construct(\PDO $pdo, $timeout = 3)
    {
        if ($pdo->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION) {
            throw new \InvalidArgumentException("The pdo must have PDO::ERRMODE_EXCEPTION set.");
        }

        try {
            if ($pdo->getAttribute(\PDO::ATTR_AUTOCOMMIT)) {
                throw new \InvalidArgumentException("PDO::ATTR_AUTOCOMMIT should be disabled.");
            }
        } catch (\PDOException $e) {
            /*
             * Ignore this, as some drivers would throw an exception for an
             * unsupported attribute (e.g. Postgres).
             */
        }

        $this->pdo  = $pdo;
        $this->loop = new Loop($timeout);
    }
    
    /**
     * Executes the critical code within a transaction.
     *
     * It's up to the user to set the correct transaction isolation level.
     * However if the transaction fails, the code will be executed again in a
     * new transaction. Therefore the code must not have any side effects
     * besides SQL statements. Also the isolation level should be conserved for
     * the repeated transaction.
     *
     * A transaction is considered as failed if a PDOException or an exception
     * which has a PDOException as any previous exception was raised.
     *
     * If the code throws any other exception, the transaction is rolled back
     * and won't  be replayed.
     *
     * @param callable $code The synchronized execution block.
     * @return mixed The return value of the execution block.
     *
     * @throws \Exception The execution block threw an exception.
     * @throws LockAcquireException The transaction was not commited.
     */
    public function synchronized(callable $code)
    {
        return $this->loop->execute(function () use ($code) {
            try {
                // BEGIN
                $this->pdo->beginTransaction();
                
            } catch (\PDOException $e) {
                throw new LockAcquireException("Could not begin transaction.", 0, $e);
            }
            
            try {
                // Unit of work
                $result = call_user_func($code);
                $this->pdo->commit();
                $this->loop->end();
                return $result;

            } catch (\Exception $e) {
                $this->rollBack($e);
                
                if ($this->hasPDOException($e)) {
                    return; // Replay
                    
                } else {
                    throw $e;
                }
            }
        });
    }
    
    /**
     * Checks if an exception or any of its previous exceptions is a PDOException.
     *
     * @param \Exception $exception The exception.
     * @return boolean True if there's a PDOException.
     */
    private function hasPDOException(\Exception $exception)
    {
        if ($exception instanceof \PDOException) {
            return true;
            
        }
        if ($exception->getPrevious() === null) {
            return false;

        }
        return $this->hasPDOException($exception->getPrevious());
    }
    
    /**
     * Rolls back a transaction.
     *
     * @param \Exception $exception The causing exception.
     *
     * @throws LockAcquireException The roll back failed.
     */
    private function rollBack(\Exception $exception)
    {
        try {
            $this->pdo->rollBack();

        } catch (\PDOException $e2) {
            throw new LockAcquireException(
                "Could not roll back transaction: {$e2->getMessage()})",
                0,
                $exception
            );
        }
    }
}
