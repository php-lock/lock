<?php

declare(strict_types=1);

namespace Malkusch\Lock\Exception;

use Malkusch\Lock\Util\LockUtil;

/**
 * This exception should be thrown when for example the lock is released or the
 * lock times out before the critical code has finished execution. This is a
 * serious exception. Side effects might have happened while the critical code
 * was executed outside of the lock which should not be trusted to be valid.
 *
 * Should only be used in contexts where the lock is being released.
 *
 * @see \Malkusch\Lock\Mutex\AbstractSpinlockMutex::unlock()
 */
class ExecutionOutsideLockException extends LockReleaseException
{
    /**
     * @param float $elapsedTime Total elapsed time of the synchronized code callback execution
     * @param float $timeout     The lock timeout in seconds
     */
    public static function create(float $elapsedTime, float $timeout): self
    {
        return new self(\sprintf(
            'The code executed for %s seconds. But the timeout is %s seconds. The last %s seconds were executed outside of the lock.',
            LockUtil::getInstance()->formatTimeout($elapsedTime),
            LockUtil::getInstance()->formatTimeout($timeout),
            LockUtil::getInstance()->formatTimeout(round($elapsedTime, 6) - round($timeout, 6))
        ));
    }
}
