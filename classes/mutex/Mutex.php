<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\ExecutionOutsideLockException;
use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
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
     * @param callable $code The synchronized execution block.
     * @return mixed The return value of the execution block.
     *
     * @throws \Exception The execution block threw an exception.
     * @throws LockAcquireException The mutex could not be acquired, no further side effects.
     * @throws LockReleaseException The mutex could not be released, the code was already executed.
     * @throws ExecutionOutsideLockException Some code was executed outside of the lock.
     */
    abstract public function synchronized(callable $code);
    
    /**
     * Performs a double-checked locking pattern.
     *
     * Call {@link DoubleCheckedLocking::then()} on the returned object.
     *
     * Example:
     * <code>
     * $mutex->check(function () use ($bankAccount, $amount) {
     *     return $bankAccount->getBalance() >= $amount;
     *
     * })->then(function () use ($bankAccount, $amount) {
     *     $bankAccount->withdraw($amount);
     * });
     * </code>
     *
     * @return DoubleCheckedLocking The double-checked locking pattern.
     */
    public function check(callable $check)
    {
        $doubleCheckedLocking = new DoubleCheckedLocking($this);
        $doubleCheckedLocking->setCheck($check);
        return $doubleCheckedLocking;
    }
}
