<?php

declare(strict_types=1);

namespace Malkusch\Lock\Util;

use Malkusch\Lock\Exception\LockAcquireTimeoutException;

/**
 * Repeats executing a code until it was successful.
 */
class Loop
{
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

        $minWaitSecs = 0.1e-3; // 0.1 ms
        $maxWaitSecs = max(0.05, min(25, $timeout / 120)); // 50 ms to 25 s, based on timeout

        $result = null;
        for ($i = 0;; ++$i) {
            $result = $code();
            if (!$this->looping) { // @phpstan-ignore booleanNot.alwaysFalse
                // The $code callback has called $this->end() and the lock has been acquired.

                break;
            }

            // Calculate max time remaining, don't sleep any longer than that.
            $remainingSecs = $deadlineTs - microtime(true);
            if ($remainingSecs <= 0) {
                break;
            }

            $minSecs = min(
                $minWaitSecs * 1.5 ** $i,
                max($minWaitSecs, $maxWaitSecs / 2)
            );
            $maxSecs = min($minSecs * 2, $maxWaitSecs);
            $sleepMicros = min(
                max(10, LockUtil::getInstance()->castFloatToInt($remainingSecs * 1e6)),
                random_int(LockUtil::getInstance()->castFloatToInt($minSecs * 1e6), LockUtil::getInstance()->castFloatToInt($maxSecs * 1e6))
            );

            usleep($sleepMicros);
        }

        if (microtime(true) >= $deadlineTs) {
            throw LockAcquireTimeoutException::create($timeout);
        }

        return $result;
    }
}
