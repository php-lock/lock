<?php

namespace malkusch\lock\exception;

/**
 * A timeout was exceeded.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 */
class TimeoutException extends LockAcquireException
{
    /**
     * @param int $timeout
     * @return TimeoutException
     */
    public static function create($timeout)
    {
        return new self(sprintf("Timeout of %d seconds exceeded.", $timeout));
    }
}
