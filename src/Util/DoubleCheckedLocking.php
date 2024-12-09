<?php

declare(strict_types=1);

namespace Malkusch\Lock\Util;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Mutex\Mutex;

/**
 * The double-checked locking pattern.
 *
 * You should not instantiate this class directly. Use
 * {@link Mutex::check()}.
 *
 * @internal
 */
class DoubleCheckedLocking
{
    private Mutex $mutex;

    /** @var callable(): bool */
    private $check;

    /**
     * @param callable(): bool $check Callback that decides if the lock should be acquired and is rechecked
     *                                after a lock has been acquired
     */
    public function __construct(Mutex $mutex, callable $check)
    {
        $this->mutex = $mutex;
        $this->check = $check;
    }

    /**
     * Executes a block of code only after the check callback passes
     * before and after acquiring a lock.
     *
     * @template T
     *
     * @param callable(): T $code
     *
     * @return T|false False if check did not pass
     *
     * @throws \Throwable
     * @throws LockAcquireException
     * @throws LockReleaseException
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
