<?php

namespace malkusch\lock;

use malkusch\lock\exception\MutexException;

/**
 * Flock() based mutex implementation.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 */
class FlockMutex extends Mutex
{
    
    /**
     * @var resource $fileHandle The file handle.
     */
    private $fileHandle;
    
    /**
     * Sets the file handle.
     *
     * @param resource $fileHandle The file handle.
     */
    public function __construct($fileHandle)
    {
        $this->fileHandle = $fileHandle;
    }
    
    public function synchronized(callable $block)
    {
        if (!flock($this->fileHandle, LOCK_EX)) {
            throw new MutexException("Could not aquire lock.");
        }
        try {
            return call_user_func($block);
            
        } finally {
            if (!flock($this->fileHandle, LOCK_UN)) {
                throw new MutexException("Could not release lock.");
            }
        }
    }
}
