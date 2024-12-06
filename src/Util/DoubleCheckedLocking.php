<?php

declare(strict_types=1);

namespace Malkusch\Lock\Util;

use Malkusch\Lock\Exception\ExecutionOutsideLockException;
use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Mutex\Mutex;

/**
 * The double-checked locking pattern.
 *
 * You should not instantiate this class directly. Use
 * {@link \Malkusch\Lock\Mutex\Mutex::check()}.
 *
 * @internal
 */
class DoubleCheckedLocking
{
    /** @var Mutex */
    private $mutex;

    /** @var callable(): bool */
    private $check;

    /**
     * Constructs a new instance of the DoubleCheckedLocking pattern.
     *
     * @param Mutex            $mutex Provides methods for exclusive code execution
     * @param callable(): bool $check Callback that decides if the lock should be acquired and if the critical code
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
     * @param callable(): T $code The critical code callback
     *
     * @return T|false False if check did not pass
     *
     * @throws \Exception                    The execution callback or the check threw an exception
     * @throws LockAcquireException          The mutex could not be acquired
     * @throws LockReleaseException          The mutex could not be released
     * @throws ExecutionOutsideLockException Some code has been executed outside of the lock
     */
    public function then(callable $code)
    {
        if (!($this->check)()) {
            return false;
        }

        return $this->mutex->synchronized(function () use ($code) {
            if (!($this->check)()) {
                return false;
            }

            return $code();
        });
    }
}
