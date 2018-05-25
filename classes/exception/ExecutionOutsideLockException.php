<?php

namespace malkusch\lock\exception;

/**
 * This exception should be thrown when for example the lock is released or
 * times out before the synchronized code finished execution.
 *
 * @see \malkusch\lock\mutex\SpinlockMutex::unlock()
 *
 * @author Petr Levtonov <petr@levtonov.com>
 * @license WTFPL
 */
class ExecutionOutsideLockException extends LockReleaseException
{
}
