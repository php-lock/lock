<?php

namespace malkusch\lock;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;

/**
 * Synchronization is delegated to the DBS.
 *
 * The exclusive code is executed within a transaction. If the code
 * throws an exception, the transaction is rolled back. It's up to
 * the user to set the correct transaction isolation level.
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
     * Sets the PDO.
     *
     * @param \PDO $pdo The PDO.
     */
    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    public function synchronized(callable $block)
    {
        if (!$this->pdo->beginTransaction()) {
            throw new LockAcquireException("Could not begin transaction.");
        }

        try {
            $result = call_user_func($block);
            
        } catch (\Exception $e) {
            if (!$this->pdo->rollBack()) {
                throw new LockReleaseException("Could not roll back transaction.", 0, $e);
            }
            throw $e;
            
        }
        if (!$this->pdo->commit()) {
            throw new LockReleaseException("Could not commit transaction.");

        }
        return $result;
    }
}
