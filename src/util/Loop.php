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
    /**
     * Minimum time that we want to wait, between lock checks. In micro seconds.
     *
     * @var float
     */
    private const MINIMUM_WAIT_US = 1e4; // 0.01 seconds

    /**
     * Maximum time that we want to wait, between lock checks. In micro seconds.
     *
     * @var float
     */
    private const MAXIMUM_WAIT_US = 5e5; // 0.50 seconds

    /** @var float the timeout in seconds */
    private $timeout;

    /** @var bool true while code execution is repeating */
    private $looping = false;

    /**
     * Sets the timeout. The default is 3 seconds.
     *
     * @param float $timeout The timeout in seconds. The default is 3 seconds.
     *
     * @throws \LengthException the timeout must be greater than 0
     */
    public function __construct(float $timeout = 3)
    {
        if ($timeout <= 0) {
            throw new \LengthException(\sprintf(
                'The timeout must be greater than 0. %d was given.',
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
     * @param callable(): T $code the to be executed code callback
     *
     * @return T the return value of the executed code callback
     *
     * @throws \Exception       the execution callback threw an exception
     * @throws TimeoutException the timeout has been reached
     */
    public function execute(callable $code)
    {
        $this->looping = true;

        // At this time, the lock will time out.
        $deadline = microtime(true) + $this->timeout;

        $result = null;
        for ($i = 0; $this->looping && microtime(true) < $deadline; ++$i) { // @phpstan-ignore booleanAnd.leftAlwaysTrue
            $result = $code();
            if (!$this->looping) { // @phpstan-ignore booleanNot.alwaysFalse
                // The $code callback has called $this->end() and the lock has been acquired.

                return $result;
            }

            // Calculate max time remaining, don't sleep any longer than that.
            $usecRemaining = (int) (($deadline - microtime(true)) * 1e6);

            // We've ran out of time.
            if ($usecRemaining <= 0) {
                throw TimeoutException::create($this->timeout);
            }

            $min = min(
                (int) self::MINIMUM_WAIT_US * 1.25 ** $i,
                self::MAXIMUM_WAIT_US
            );
            $max = min($min * 2, self::MAXIMUM_WAIT_US);

            $usecToSleep = min($usecRemaining, random_int((int) $min, (int) $max));

            usleep($usecToSleep);
        }

        throw TimeoutException::create($this->timeout);
    }
}
