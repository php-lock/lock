<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockReleaseException;
use Throwable;

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
     * @throws \malkusch\lock\exception\LockAcquireException the lock could not
     *                                                       be acquired
     */
    abstract protected function lock(): void;

    /**
     * Releases the lock.
     *
     * @throws \malkusch\lock\exception\LockReleaseException the lock could not
     *                                                       be released
     */
    abstract protected function unlock(): void;

    public function synchronized(callable $code)
    {
        $this->lock();

        $codeResult = null;
        $codeException = null;
        try {
            $codeResult = $code();
        } catch (Throwable $exception) {
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
