<?php

declare(strict_types=1);

namespace Malkusch\Lock\Util;

use Malkusch\Lock\Exception\DeadlineException;
use Malkusch\Lock\Exception\LockAcquireException;

/**
 * Timeout based on a scheduled alarm.
 *
 * This class requires the pcntl module and supports the cli sapi only.
 *
 * Only integer timeout is supported - https://github.com/php/php-src/issues/11828.
 *
 * @internal
 */
final class PcntlTimeout
{
    /** In seconds */
    private int $timeout;

    /**
     * @param int $timeout In seconds
     */
    public function __construct(int $timeout)
    {
        if (!self::isSupported()) {
            throw new \RuntimeException('PCNTL extension is required');
        }

        if ($timeout <= 0) {
            throw new \InvalidArgumentException('Timeout must be positive and non zero');
        }

        $this->timeout = $timeout;
    }

    /**
     * Runs the code and would eventually timeout.
     *
     * This method has the side effect, that any signal handler for SIGALRM will
     * be reset to the default hanlder (SIG_DFL). It also expects that there is
     * no previously scheduled alarm. If your application uses alarms
     * ({@link pcntl_alarm()}) or a signal handler for SIGALRM, don't use this
     * method. It will interfer with your application and lead to unexpected
     * behaviour.
     *
     * @template T
     *
     * @param callable(): T $code Executed code block
     *
     * @return T
     *
     * @throws \Throwable
     * @throws DeadlineException    Running the code hit the deadline
     * @throws LockAcquireException Installing the timeout failed
     */
    public function timeBoxed(callable $code)
    {
        if (pcntl_alarm($this->timeout) !== 0) {
            throw new LockAcquireException('Existing process alarm is not supported');
        }

        $origSignalHandler = pcntl_signal_get_handler(\SIGALRM);

        $timeout = $this->timeout;
        $signalHandlerFx = static function () use ($timeout): void {
            throw new DeadlineException(sprintf(
                'Timebox hit deadline of %d seconds',
                $timeout
            ));
        };

        if (!pcntl_signal(\SIGALRM, $signalHandlerFx)) {
            throw new LockAcquireException('Failed to install signal handler');
        }

        try {
            return $code();
        } finally {
            pcntl_alarm(0);
            try {
                pcntl_signal_dispatch();
            } finally {
                pcntl_signal(\SIGALRM, $origSignalHandler);
            }
        }
    }

    /**
     * Returns true if this class is supported by the PHP runtime.
     *
     * This class requires the pcntl module. This method checks if
     * it is available.
     */
    public static function isSupported(): bool
    {
        return \PHP_SAPI === 'cli'
            && extension_loaded('pcntl')
            && function_exists('pcntl_alarm')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_signal_dispatch');
    }
}
