<?php

namespace malkusch\lock\exception;

/**
 * This exception should be thrown when for example the lock is released or
 * times out before the synchronized code finished execution.
 *
 * @see \malkusch\lock\mutex\SpinlockMutex::unlock()
 *
 * @author Petr Levtonov <petr@levtonov.com>
 * @license WTFPL
 */
class ExecutionOutsideLockException extends LockReleaseException
{
    public static function create($elapsed_time, $timeout)
    {
        $message = sprintf(
            "The code executed for %.2F seconds. But the timeout is %d " .
            "seconds. The last %.2F seconds were executed outside the lock.",
            $elapsed_time,
            $timeout,
            $elapsed_time - $timeout
        );

        return new self($message);
    }
}
