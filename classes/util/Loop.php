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
        $deadlineTs = microtime(true) + $this->timeout;

        $minWaitSecs = 0.1e-3; // 0.1 ms
        $maxWaitSecs = max(0.05, min(25, $this->timeout / 120)); // 50 ms to 25 s, based on timeout

        $result = null;
        for ($i = 0; ; ++$i) {
            $result = $code();
            if (!$this->looping) {
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
                max(10, (int)($remainingSecs * 1e6)),
                random_int((int)($minSecs * 1e6), (int)($maxSecs * 1e6))
            );

            usleep($sleepMicros);
        }

        if (microtime(true) >= $deadlineTs) {
            throw TimeoutException::create($this->timeout);
        }

        return $result;
    }
}
