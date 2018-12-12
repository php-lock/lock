<?php

declare(strict_types=1);

namespace malkusch\lock\util;

use malkusch\lock\mutex\Mutex;

/**
 * The double-checked locking pattern.
 *
 * You should not instantiate this class directly. Use
 * {@link \malkusch\lock\mutex\Mutex::check()}.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 */
class DoubleCheckedLocking
{
    /**
     * @var \malkusch\lock\mutex\Mutex The mutex.
     */
    private $mutex;

    /**
     * @var callable The check.
     */
    private $check;

    /**
     * Constructs a new instance of the DoubleCheckedLocking pattern.
     *
     * @param \malkusch\lock\mutex\Mutex $mutex Provides methods for exclusive
     * code execution.
     * @param callable $check Callback that decides if the lock should be
     * acquired and if the critical code callback should be executed after
     * acquiring the lock.
     */
    public function __construct(Mutex $mutex, callable $check)
    {
        $this->mutex = $mutex;
        $this->check = $check;
    }

    /**
     * Executes a synchronized callback only after the check callback passes
     * before and after acquiring the lock.
     *
     * If then returns boolean boolean false, the check did not pass before or
     * after acquiring the lock. A boolean false can also be returned from the
     * critical code callback to indicate that processing did not occure or has
     * failed. It is up to the user to decide the last point.
     *
     * @param callable $code The critical code callback.
     * @throws \Exception The execution callback or the check threw an
     * exception.
     * @throws \malkusch\lock\exception\LockAcquireException The mutex could not
     * be acquired.
     * @throws \malkusch\lock\exception\LockReleaseException The mutex could not
     * be released.
     * @throws \malkusch\lock\exception\ExecutionOutsideLockException Some code
     * has been executed outside of the lock.
     * @return mixed Boolean false if check did not pass or mixed for what ever
     * the critical code callback returns.
     */
    public function then(callable $code)
    {
        if (!\call_user_func($this->check)) {
            return false;
        }

        return $this->mutex->synchronized(function () use ($code) {
            if (!\call_user_func($this->check)) {
                return false;
            }

            return $code();
        });
    }
}
