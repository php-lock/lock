<?php

declare(strict_types=1);

namespace malkusch\lock\util;

use malkusch\lock\exception\TimeoutException;

/**
 * Repeats executing a code until it was successful.
 *
 * @internal
 */
class Loop
{
    /** @var float The timeout in seconds */
    private $timeout;

    /** @var bool true While code execution is repeating */
    private $looping = false;

    /**
     * Sets the timeout.
     *
     * @param float $timeout the timeout in seconds
     *
     * @throws \LengthException The timeout must be greater than 0
     */
    public function __construct(float $timeout = 3)
    {
        if ($timeout <= 0) {
            throw new \LengthException(\sprintf(
                'The timeout must be greater than 0 (%d was given)',
                $timeout
            ));
        }

        $this->timeout = $timeout;
    }

    /**
     * Notifies that this was the last iteration.
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
     * by calling {@link \malkusch\lock\util\Loop::end()}. I.e. the only side
     * effects of the code may happen after a successful execution.
     *
     * If the code throws an exception it will stop repeating the execution.
     *
     * @template T
     *
     * @param callable(): T $code The to be executed code callback
     *
     * @return T
     *
     * @throws \Exception       The execution callback threw an exception
     * @throws TimeoutException The timeout has been reached
     */
    public function execute(callable $code)
    {
        $this->looping = true;

        // At this time, the lock will time out.
        $deadlineTs = microtime(true) + $this->timeout;

        $minWaitSecs = 0.1e-3; // 0.1 ms
        $maxWaitSecs = max(0.05, min(25, $this->timeout / 120)); // 50 ms to 25 s, based on timeout

        $result = null;
        for ($i = 0;; ++$i) {
            $result = $code();
            if (!$this->looping) { // @phpstan-ignore booleanNot.alwaysFalse
                // The $code callback has called $this->end() and the lock has been acquired.

                return $result;
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
                max(10, (int) ($remainingSecs * 1e6)),
                random_int((int) ($minSecs * 1e6), (int) ($maxSecs * 1e6))
            );

            usleep($sleepMicros);
        }

        if (microtime(true) >= $deadlineTs) {
            throw TimeoutException::create($this->timeout);
        }

        return $result;
    }
}
