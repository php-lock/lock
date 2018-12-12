<?php

declare(strict_types=1);

namespace malkusch\lock\util;

use LengthException;
use malkusch\lock\exception\TimeoutException;

/**
 * Repeats executing a code until it was successful.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 * @internal
 */
class Loop
{
    /**
     * Minimum time that we want to wait, between lock checks. In micro seconds.
     *
     * @var double
     */
    private const MINIMUM_WAIT_US = 1e4;

    /**
     * Maximum time that we want to wait, between lock checks. In micro seconds.
     *
     * @var double
     */
    private const MAXIMUM_WAIT_US = 1e6;

    /**
     * @var int The timeout in seconds.
     */
    private $timeout;

    /**
     * @var bool True while code execution is repeating.
     */
    private $looping;

    /**
     * Sets the timeout. The default is 3 seconds.
     *
     * @param int $timeout The timeout in seconds. The default is 3 seconds.
     * @throws \LengthException The timeout must be greater than 0.
     */
    public function __construct(int $timeout = 3)
    {
        if ($timeout <= 0) {
            throw new LengthException(\sprintf(
                'The timeout must be greater than 0. %d was given.',
                $timeout
            ));
        }

        $this->timeout = $timeout;
        $this->looping = false;
    }

    /**
     * Notifies that this was the last iteration.
     *
     * @return void
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
     * @param callable $code The to be executed code callback.
     * @throws \Exception The execution callback threw an exception.
     * @throws \malkusch\lock\exception\TimeoutException The timeout has been
     * reached.
     * @return mixed The return value of the executed code callback.
     *
     */
    public function execute(callable $code)
    {
        $this->looping = true;

        // At this time, the lock will time out.
        $deadline = microtime(true) + $this->timeout;

        $result = null;
        for ($i = 0; $this->looping && microtime(true) < $deadline; ++$i) {
            $result = $code();
            if (!$this->looping) {
                break;
            }

            // Calculate max time remaining, don't sleep any longer than that.
            $usecRemaining = intval(($deadline - microtime(true)) * 1e6);

            // We've ran out of time.
            if ($usecRemaining <= 0) {
                throw TimeoutException::create($this->timeout);
            }

            $min = min(
                (int) self::MINIMUM_WAIT_US * 1.5 ** $i,
                self::MAXIMUM_WAIT_US
            );
            $max = min($min * 2, self::MAXIMUM_WAIT_US);

            $usecToSleep = min($usecRemaining, random_int((int)$min, (int)$max));

            usleep($usecToSleep);
        }

        if (microtime(true) >= $deadline) {
            throw TimeoutException::create($this->timeout);
        }

        return $result;
    }
}
