<?php

namespace malkusch\lock;

/**
 * System V IPC based mutex implementation.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 */
class Semaphore extends Mutex
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
     * @param int $key The System V IPC key.
     * @throws MutexException The Semaphore could not be created.
     */
    public function __construct($key)
    {
        $this->semaphore = sem_get($key);
        if (!is_resource($this->semaphore)) {
            throw new MutexException("Could not get Semaphore for key '$key'.");
        }
    }
    
    public function synchronized(callable $block)
    {
        if (!sem_acquire($this->semaphore)) {
            throw new MutexException("Could not acquire Semaphore.");
        }
        try {
            return call_user_func($block);
            
        } finally {
            if (!sem_release($this->semaphore)) {
                throw new MutexException("Could not release Semaphore.");
            }
        }
    }
}
