<?php

declare(strict_types=1);

namespace malkusch\lock\util;

use malkusch\lock\exception\ExecutionOutsideLockException;
use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use malkusch\lock\mutex\Mutex;

/**
 * The double-checked locking pattern.
 *
 * You should not instantiate this class directly. Use
 * {@link \malkusch\lock\mutex\Mutex::check()}.
 */
class DoubleCheckedLocking
{
    /**
     * @var Mutex the mutex
     */
    private $mutex;

    /**
     * @var callable(): bool the check
     */
    private $check;

    /**
     * Constructs a new instance of the DoubleCheckedLocking pattern.
     *
     * @param Mutex            $mutex provides methods for exclusive code execution
     * @param callable(): bool $check callback that decides if the lock should be acquired and if the critical code
     *                                callback should be executed after acquiring the lock
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
     * @template T
     *
     * @param callable(): T $code the critical code callback
     *
     * @return T|false boolean false if check did not pass or mixed for what ever the critical code callback returns
     *
     * @throws \Exception                    the execution callback or the check threw an exception
     * @throws LockAcquireException          the mutex could not be acquired
     * @throws LockReleaseException          the mutex could not be released
     * @throws ExecutionOutsideLockException some code has been executed outside of the lock
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
