<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;

/**
 * Flock() based mutex implementation.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see flock()
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
     * @throws \InvalidArgumentException The file handle is not a valid resource.
     */
    public function __construct($fileHandle)
    {
        if (!is_resource($fileHandle)) {
            throw new \InvalidArgumentException("The file handle is not a valid resource.");
            
        }
        $this->fileHandle = $fileHandle;
    }
    
    public function synchronized(callable $code)
    {
        if (!flock($this->fileHandle, LOCK_EX)) {
            throw new LockAcquireException("Could not aquire lock.");
        }
        try {
            return call_user_func($code);
            
        } finally {
            if (!flock($this->fileHandle, LOCK_UN)) {
                throw new LockReleaseException("Could not release lock.");
            }
        }
    }
}
