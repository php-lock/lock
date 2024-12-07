<?php

declare(strict_types=1);

namespace Malkusch\Lock\Exception;

/**
 * Usually extended by more meaningful classes.
 */
class MutexException extends \RuntimeException
{
    /** Not enough redis servers */
    public const CODE_REDLOCK_NOT_ENOUGH_SERVERS = 23786;
}
