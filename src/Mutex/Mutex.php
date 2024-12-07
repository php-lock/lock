<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Exception\ExecutionOutsideLockException;
use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Util\DoubleCheckedLocking;

/**
 * Mutex interface for exclusive execution.
 */
interface Mutex
{
    /**
     * Executes a block of code exclusively.
     *
     * This method implements Java's synchronized semantic. I.e. this method
     * waits until a lock could be acquired, executes the code exclusively and
     * releases the lock.
     *
     * The code block may throw an exception. In this case the lock will be
     * released as well.
     *
     * @template T
     *
     * @param callable(): T $code The synchronized execution callback
     *
     * @return T
     *
     * @throws \Throwable                    The execution callback threw an exception
     * @throws LockAcquireException          The mutex could not be acquired, no further side effects
     * @throws LockReleaseException          The mutex could not be released, the code was already executed
     * @throws ExecutionOutsideLockException Some code has been executed outside of the lock
     */
    public function synchronized(callable $code);

    /**
     * Performs a double-checked locking pattern.
     *
     * Call {@link \Malkusch\Lock\Util\DoubleCheckedLocking::then()} on the
     * returned object.
     *
     * Example:
     * <code>
     * $result = $mutex->check(static function () use ($bankAccount, $amount) {
     *     return $bankAccount->getBalance() >= $amount;
     * })->then(static function () use ($bankAccount, $amount) {
     *     return $bankAccount->withdraw($amount);
     * });
     * </code>
     *
     * @param callable(): bool $check Callback that decides if the lock should be acquired and if the synchronized
     *                                callback should be executed after acquiring the lock
     *
     * @return DoubleCheckedLocking The double-checked locking pattern
     */
    public function check(callable $check): DoubleCheckedLocking;
}
