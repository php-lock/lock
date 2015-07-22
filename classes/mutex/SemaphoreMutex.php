<?php

namespace malkusch\lock\mutex;

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
     * @var resource The semaphore id.
     */
    private $semaphore;
    
    /**
     * Sets the semaphore id.
     *
     * Use {@link sem_get()} to create the semaphore id.
     *
     * @param resource semaphore The semaphore id.
     * @throws \InvalidArgumentException The semaphore id is not a valid resource.
     */
    public function __construct($semaphore)
    {
        if (!is_resource($semaphore)) {
            throw new \InvalidArgumentException("The semaphore id is not a valid resource.");
            
        }
        $this->semaphore = $semaphore;
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
