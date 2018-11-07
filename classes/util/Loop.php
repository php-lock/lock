<?php

namespace malkusch\lock\util;

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
     * Minimum time that we want to wait, between lock checks.
     *
     * In micro seconds.
     */
    private const MINIMUM_WAIT_US = 1e4;

    /**
     * Maximum time that we want to wait, between lock checks.
     *
     * In micro seconds.
     */
    private const MAXIMUM_WAIT_US = 1e6;
    
    /**
     * @var int The timeout in seconds.
     */
    private $timeout;
    
    /**
     * @var bool True while code should be repeated.
     */
    private $looping = false;
    
    /**
     * Sets the timeout.
     *
     * The default is 3 seconds.
     *
     * @param int $timeout The timeout in seconds.
     * @throws \LengthException The timeout must be greater than 0.
     */
    public function __construct(int $timeout = 3)
    {
        if ($timeout <= 0) {
            throw new \LengthException("The timeout must be greater than 0. '$timeout' was given");
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
     * by calling {@link Loop::end()}. I.e. the only side effects
     * of the code may happen after a successful execution.
     *
     * If the code throws an exception it will stop repeating the execution.
     *
     * @param callable $code The executed code block.
     * @return mixed The return value of the executed block.
     *
     * @throws \Exception The execution block threw an exception.
     * @throws TimeoutException The timeout was reached.
     */
    public function execute(callable $code)
    {
        $this->looping = true;

        $deadline = microtime(true) + $this->timeout; // At this time, the lock will time out.
        $result = null;

        for ($i = 0; $this->looping && microtime(true) < $deadline; $i++) {
            $result = $code();
            if (!$this->looping) {
                break;
            }

            /*
             * Calculate max time remaining, don't sleep any longer than that.
             */
            $usecRemaining = \intval(($deadline - microtime(true))  * 1e6);

            if ($usecRemaining <= 0) {
                /*
                 * We've ran out of time.
                 */
                throw TimeoutException::create($this->timeout);
            }

            $min = min((int) self::MINIMUM_WAIT_US * 1.5 ** $i, self::MAXIMUM_WAIT_US);
            $max = min($min * 2, self::MAXIMUM_WAIT_US);

            $usecToSleep = \min($usecRemaining, \random_int($min, $max));

            usleep($usecToSleep);
        }

        if (microtime(true) >= $deadline) {
            throw TimeoutException::create($this->timeout);
        }

        return $result;
    }
}
