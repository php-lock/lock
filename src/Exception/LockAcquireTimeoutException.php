<?php

declare(strict_types=1);

namespace Malkusch\Lock\Exception;

use Malkusch\Lock\Util\LockUtil;

class LockAcquireTimeoutException extends LockAcquireException
{
    /**
     * @param float $acquireTimeout In seconds
     */
    public static function create(float $acquireTimeout): self
    {
        return new self('Lock acquire timeout of '
            . LockUtil::getInstance()->formatTimeout($acquireTimeout)
            . ' seconds has been exceeded');
    }
}
