<?php

declare(strict_types=1);

namespace Malkusch\Lock\Exception;

/**
 * Failed to acquire a lock.
 *
 * This exception implies that the critical code was not executed.
 */
class LockAcquireException extends MutexException {}
