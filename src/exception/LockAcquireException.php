<?php

declare(strict_types=1);

namespace malkusch\lock\exception;

/**
 * Lock acquire exception.
 *
 * Used when the lock could not be acquired. This exception implies that the
 * critical code was not executed, or at least had no side effects.
 */
class LockAcquireException extends MutexException
{
}
