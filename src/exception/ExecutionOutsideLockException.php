<?php

declare(strict_types=1);

namespace malkusch\lock\exception;

/**
 * Execution outside lock exception.
 *
 * This exception should be thrown when for example the lock is released or the
 * lock times out before the critical code has finished execution. This is a
 * serious exception. Side effects might have happened while the critical code
 * was executed outside of the lock which should not be trusted to be valid.
 *
 * Should only be used in contexts where the is being released.
 *
 * @see \malkusch\lock\mutex\SpinlockMutex::unlock()
 */
class ExecutionOutsideLockException extends LockReleaseException
{
    /**
     * Creates a new instance of the ExecutionOutsideLockException class.
     *
     * @param float $elapsedTime total elapsed time of the synchronized code callback execution
     * @param float $timeout     the lock timeout in seconds
     *
     * @return self execution outside lock exception
     */
    public static function create(float $elapsedTime, float $timeout): self
    {
        $elapsedTimeStr = (string) round($elapsedTime, 6);
        if (\is_finite($elapsedTime) && strpos($elapsedTimeStr, '.') === false) {
            $elapsedTimeStr .= '.0';
        }

        $timeoutStr = (string) round($timeout, 6);
        if (\is_finite($timeout) && strpos($timeoutStr, '.') === false) {
            $timeoutStr .= '.0';
        }

        $overTime = round($elapsedTime, 6) - round($timeout, 6);
        $overTimeStr = (string) round($overTime, 6);
        if (\is_finite($timeout) && strpos($overTimeStr, '.') === false) {
            $overTimeStr .= '.0';
        }

        return new self(\sprintf(
            'The code executed for %s seconds. But the timeout is %s ' .
            'seconds. The last %s seconds were executed outside of the lock.',
            $elapsedTimeStr,
            $timeoutStr,
            $overTimeStr
        ));
    }
}
