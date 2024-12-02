<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

use malkusch\lock\util\DoubleCheckedLocking;

/**
 * The mutex provides methods for exclusive execution.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 */
abstract class Mutex
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
     * @param callable $code The synchronized execution callback.
     * @throws \Exception The execution callback threw an exception.
     * @throws \malkusch\lock\exception\LockAcquireException The mutex could not
     * be acquired, no further side effects.
     * @throws \malkusch\lock\exception\LockReleaseException The mutex could not
     * be released, the code was already executed.
     * @throws \malkusch\lock\exception\ExecutionOutsideLockException Some code
     * has been executed outside of the lock.
     * @return mixed The return value of the execution callback.
     */
    abstract public function synchronized(callable $code);

    /**
     * Performs a double-checked locking pattern.
     *
     * Call {@link \malkusch\lock\util\DoubleCheckedLocking::then()} on the
     * returned object.
     *
     * Example:
     * <code>
     * $result = $mutex->check(function () use ($bankAccount, $amount) {
     *     return $bankAccount->getBalance() >= $amount;
     * })->then(function () use ($bankAccount, $amount) {
     *     return $bankAccount->withdraw($amount);
     * });
     * </code>
     *
     * @param callable $check Callback that decides if the lock should be
     * acquired and if the synchronized callback should be executed after
     * acquiring the lock.
     * @return \malkusch\lock\util\DoubleCheckedLocking The double-checked
     * locking pattern.
     */
    public function check(callable $check): DoubleCheckedLocking
    {
        return new DoubleCheckedLocking($this, $check);
    }
}
