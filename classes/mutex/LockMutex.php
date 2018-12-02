<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;

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
    abstract protected function lock(): void;

    /**
     * Releases the lock.
     *
     * @throws LockReleaseException The lock could not be released.
     */
    abstract protected function unlock(): void;

    public function synchronized(callable $code)
    {
        $this->lock();

        $code_result = null;
        $code_exception = null;
        try {
            $code_result = $code();
        } catch (\Throwable $exception) {
            $code_exception = $exception;

            throw $exception;
        } finally {
            try {
                $this->unlock();
            } catch (LockReleaseException $lock_exception) {
                $lock_exception->setCodeResult($code_result);
                if ($code_exception !== null) {
                    $lock_exception->setCodeException($code_exception);
                }

                throw $lock_exception;
            }
        }

        return $code_result;
    }
}
