<?php

declare(strict_types=1);

namespace malkusch\lock\exception;

/**
 * Timeout exception.
 *
 * A timeout has been exceeded exception. Should only be used in contexts where
 * the lock is being acquired.
 */
class TimeoutException extends LockAcquireException
{
    /**
     * Creates a new instance of the TimeoutException class.
     *
     * @param float $timeout The timeout in seconds
     */
    public static function create(float $timeout): self
    {
        $timeoutStr = (string) round($timeout, 6);
        if (\is_finite($timeout) && strpos($timeoutStr, '.') === false) {
            $timeoutStr .= '.0';
        }

        return new self(\sprintf('Timeout of %s seconds exceeded', $timeoutStr));
    }
}
