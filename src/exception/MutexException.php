<?php

declare(strict_types=1);

namespace malkusch\lock\exception;

/**
 * Mutex exception.
 *
 * Generic exception for any other not covered reason. Usually extended by
 * child classes.
 */
class MutexException extends \RuntimeException implements PhpLockException
{
    /** @var int not enough redis servers */
    public const REDIS_NOT_ENOUGH_SERVERS = 1;
}
