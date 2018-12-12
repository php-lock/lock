<?php

declare(strict_types=1);

namespace malkusch\lock\exception;

/**
 * Timeout exception.
 *
 * A timeout has been exceeded exception. Should only be used in contexts where
 * the lock is being acquired.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 */
class TimeoutException extends LockAcquireException
{
    /**
     * Creates a new instance of the TimeoutException class.
     *
     * @param int $timeout The timeout in seconds.
     * @return self A timeout has been exceeded exception.
     */
    public static function create(int $timeout): self
    {
        return new self(\sprintf('Timeout of %d seconds exceeded.', $timeout));
    }
}
