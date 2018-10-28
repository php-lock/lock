<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockReleaseException;
use malkusch\lock\exception\LockAcquireException;

/**
 * Locking mutex.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 * @internal
 */
abstract class LockMutex extends Mutex
{

    /**
     * Acquires the lock.
     *
     * This method blocks until the lock was acquired.
     *
     * @throws LockAcquireException The lock could not be acquired.
     */
    abstract protected function lock();

    /**
     * Releases the lock.
     *
     * @throws LockReleaseException The lock could not be released.
     */
    abstract protected function unlock();
    
    public function synchronized(callable $code)
    {
        $this->lock();
        try {
            return call_user_func($code);
        } finally {
            $this->unlock();
        }
    }
}
