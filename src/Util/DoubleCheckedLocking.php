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
     * @param callable(): bool $check Decides if a lock should be acquired and is rechecked after the lock has been acquired
     */
    public function __construct(Mutex $mutex, callable $check)
    {
        $this->mutex = $mutex;
        $this->check = $check;
    }

    /**
     * Execute a block of code only after the check callback passes before and after acquiring a lock.
     *
     * @template TSuccess
     * @template TFail = never
     *
     * @param callable(): TSuccess $successFx
     * @param callable(): TFail    $failFx
     *
     * @return TSuccess|TFail|false False if check did not pass
     *
     * @throws \Throwable
     * @throws LockAcquireException
     * @throws LockReleaseException
     */
    public function then(callable $successFx, ?callable $failFx = null)
    {
        if (!($this->check)()) {
            return $failFx !== null
                ? $failFx()
                : false;
        }

        return $this->mutex->synchronized(function () use ($successFx, $failFx) {
            if (!($this->check)()) {
                return $failFx !== null
                    ? $failFx()
                    : false;
            }

            return $successFx();
        });
    }
}
