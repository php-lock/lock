<?php

declare(strict_types=1);

namespace Malkusch\Lock\Exception;

/**
 * Mutex exception.
 *
 * Generic exception for any other not covered reason. Usually extended by
 * child classes.
 */
class MutexException extends \RuntimeException implements PhpLockException
{
    /** Not enough redis servers */
    public const CODE_REDLOCK_NOT_ENOUGH_SERVERS = 23786;
}
