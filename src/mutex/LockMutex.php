<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;

/**
 * Locking mutex.
 *
 * @internal
 */
abstract class LockMutex extends Mutex
{
    /**
     * Acquires the lock.
     *
     * This method blocks until the lock was acquired.
     *
     * @throws LockAcquireException The lock could not be acquired
     */
    abstract protected function lock(): void;

    /**
     * Releases the lock.
     *
     * @throws LockReleaseException The lock could not be released
     */
    abstract protected function unlock(): void;

    #[\Override]
    public function synchronized(callable $code)
    {
        $this->lock();

        $codeResult = null;
        $codeException = null;
        try {
            $codeResult = $code();
        } catch (\Throwable $exception) {
            $codeException = $exception;

            throw $exception;
        } finally {
            try {
                $this->unlock();
            } catch (LockReleaseException $lockReleaseException) {
                $lockReleaseException->setCodeResult($codeResult);
                if ($codeException !== null) {
                    $lockReleaseException->setCodeException($codeException);
                }

                throw $lockReleaseException;
            }
        }

        return $codeResult;
    }
}
