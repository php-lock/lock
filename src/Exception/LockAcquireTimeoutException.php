<?php

declare(strict_types=1);

namespace Malkusch\Lock\Exception;

class LockAcquireTimeoutException extends LockAcquireException
{
    /**
     * @param float $acquireTimeout In seconds
     */
    public static function create(float $acquireTimeout): self
    {
        $acquireTimeoutStr = (string) round($acquireTimeout, 6);
        if (\is_finite($acquireTimeout) && strpos($acquireTimeoutStr, '.') === false) {
            $acquireTimeoutStr .= '.0';
        }

        return new self('Lock acquire timeout of ' . $acquireTimeoutStr . ' seconds has been exceeded');
    }
}
