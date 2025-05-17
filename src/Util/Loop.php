<?php

declare(strict_types=1);

namespace Malkusch\Lock\Util;

use Malkusch\Lock\Exception\LockAcquireTimeoutException;

/**
 * Repeats executing a code until it was successful.
 */
class Loop
{
    /** Minimum time to wait between lock checks. In micro seconds. */
    private const MINIMUM_WAIT_US = 10_000;

    /** Maximum time to wait between lock checks. In micro seconds. */
    private const MAXIMUM_WAIT_US = 500_000;

    /** True while code execution is repeating */
    private bool $looping = false;

    /**
     * Notify that this was the last iteration.
     */
    public function end(): void
    {
        $this->looping = false;
    }

    /**
     * Repeats executing a code until it was successful.
     *
     * The code has to be designed in a way that it can be repeated without any
     * side effects. When execution was successful it should notify that event
     * by calling {@link Loop::end()}. I.e. the only side
     * effects of the code may happen after a successful execution.
     *
     * If the code throws an exception it will stop repeating the execution.
     *
     * @template T
     *
     * @param callable(): T $code    The to be executed code callback
     * @param float         $timeout In seconds
     *
     * @return T
     *
     * @throws \Throwable
     * @throws LockAcquireTimeoutException
     */
    public function execute(callable $code, float $timeout)
    {
        if ($timeout < 0 || is_nan($timeout)) {
            throw new \InvalidArgumentException(\sprintf(
                'The lock acquire timeout must be greater than or equal to %s (%s was given)',
                LockUtil::getInstance()->formatTimeout(0),
                LockUtil::getInstance()->formatTimeout($timeout)
            ));
        }

        $this->looping = true;

        // At this time, the lock will timeout.
        $deadlineTs = microtime(true) + $timeout;

        $result = null;
        for ($i = 0; $this->looping && microtime(true) < $deadlineTs; ++$i) { // @phpstan-ignore booleanAnd.leftAlwaysTrue
            $result = $code();
            if (!$this->looping) { // @phpstan-ignore booleanNot.alwaysFalse
                // The $code callback has called $this->end() and the lock has been acquired.

                return $result;
            }

            // Calculate max time remaining, don't sleep any longer than that.
            $usecRemaining = LockUtil::getInstance()->castFloatToInt(($deadlineTs - microtime(true)) * 1e6);

            // We've ran out of time.
            if ($usecRemaining <= 0) {
                break;
            }

            $min = min(
                self::MINIMUM_WAIT_US * 1.25 ** $i,
                self::MAXIMUM_WAIT_US
            );
            $max = min($min * 2, self::MAXIMUM_WAIT_US);

            $usecToSleep = min($usecRemaining, random_int((int) $min, (int) $max));

            usleep($usecToSleep);
        }

        throw LockAcquireTimeoutException::create($timeout);
    }
}
