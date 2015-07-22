<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\util\Loop;

/**
 * Synchronization is delegated to the DBS.
 *
 * The exclusive code is executed within a transaction.
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
     */
    public function __construct(\PDO $pdo, $timeout = 3)
    {
        $this->pdo  = $pdo;
        $this->loop = new Loop($timeout);
    }
    
    /**
     * Executes the critical code within a transaction.
     *
     * It's up to the user to set the correct transaction isolation level.
     * However if the commit fails, the code will be executed again. Therefore
     * the code must not have any side effects besides SQL statements.
     *
     * If the code throws an exception, the transaction is rolled back.
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
            if (!$this->pdo->beginTransaction()) {
                throw new LockAcquireException("Could not begin transaction.");
            }
            try {
                $result = call_user_func($code);

            } catch (\Exception $e) {
                if (!$this->pdo->rollBack()) {
                    throw new LockAcquireException("Could not roll back transaction.", 0, $e);
                }
                throw $e;
            }
            
            if (!$this->pdo->commit()) {
                if (!$this->pdo->rollBack()) {
                    throw new LockAcquireException("Could not roll back transaction.");
                }
                // repeat transaction.
                return;
            }

            $this->loop->notify();
            return $result;
        });
    }
}
