<?php

namespace malkusch\lock;

use malkusch\lock\exception\MutexException;
use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;

/**
 * System V IPC based mutex implementation.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 */
class SemaphoreMutex extends Mutex
{

    /**
     * @var resource The semaphore.
     */
    private $semaphore;
    
    /**
     * Sets the System V IPC key for the semaphore.
     *
     * You can create the key with PHP's function ftok().
     *
     * @param int $key         The System V IPC key.
     * @param int $max_acquire The number of processes that can acquire the semaphore simultaneously, default is 1.
     * @throws MutexException The Semaphore could not be created.
     */
    public function __construct($key, $max_acquire = 1)
    {
        $this->semaphore = sem_get($key, $max_acquire);
        if (!is_resource($this->semaphore)) {
            throw new MutexException("Could not get Semaphore for key '$key'.");
        }
    }
    
    public function synchronized(callable $block)
    {
        if (!sem_acquire($this->semaphore)) {
            throw new LockAcquireException("Could not acquire Semaphore.");
        }
        try {
            return call_user_func($block);
            
        } finally {
            if (!sem_release($this->semaphore)) {
                throw new LockReleaseException("Could not release Semaphore.");
            }
        }
    }
}
