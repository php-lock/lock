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
class FlockMutex extends LockMutex
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
    
    /**
     * @internal
     */
    protected function lock()
    {
        if (!flock($this->fileHandle, LOCK_EX)) {
            throw new LockAcquireException("Failed to lock the file.");
        }
    }
    
    /**
     * @internal
     */
    protected function unlock()
    {
        if (!flock($this->fileHandle, LOCK_UN)) {
            throw new LockReleaseException("Failed to unlock the file.");
        }
    }
}
