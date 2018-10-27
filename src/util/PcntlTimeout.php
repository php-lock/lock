<?php

namespace malkusch\lock\util;

use malkusch\lock\exception\DeadlineException;
use malkusch\lock\exception\TimeoutException;
use malkusch\lock\exception\LockAcquireException;

/**
 * Timeout based on a scheduled alarm.
 *
 * This class requires the pcntl module.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @internal
 */
final class PcntlTimeout
{

    /**
     * @var int Timeout in seconds
     */
    private $timeout;

    /**
     * Builds the timeout.
     *
     * @param int $timeout Timeout in seconds
     */
    public function __construct($timeout)
    {
        if (!self::isSupported()) {
            throw new \RuntimeException("PCNTL module not enabled");
        }
        if ($timeout <= 0) {
            throw new \InvalidArgumentException("Timeout must be positive and non zero");
        }
        $this->timeout = $timeout;
    }

    /**
     * Runs the code and would eventually time out.
     *
     * This method has the side effect, that any signal handler
     * for SIGALRM will be reset to the default hanlder (SIG_DFL).
     * It also expects that there is no previously scheduled alarm.
     * If your application uses alarms ({@link pcntl_alarm()}) or
     * a signal handler for SIGALRM, don't use this method. It will
     * interfer with your application and lead to unexpected behaviour.
     *
     * @param callable $code Executed code block
     * @return mixed Return value of the executed block
     *
     * @throws DeadlineException Running the code hit the deadline
     * @throws LockAcquireException Installing the timeout failed
     */
    public function timeBoxed(callable $code)
    {
        $signal = pcntl_signal(SIGALRM, function () {
            throw new DeadlineException(sprintf("Timebox hit deadline of %d seconds", $this->timeout));
        });
        if (!$signal) {
            throw new LockAcquireException("Could not install signal");
        }
        $oldAlarm = pcntl_alarm($this->timeout);
        if ($oldAlarm != 0) {
            throw new LockAcquireException("Existing alarm was not expected");
        }
        try {
            return call_user_func($code);
        } finally {
            pcntl_alarm(0);
            pcntl_signal_dispatch();
            pcntl_signal(SIGALRM, SIG_DFL);
        }
    }

    /**
     * Returns if this class is supported by the PHP runtime.
     *
     * This class requires the pcntl module. This method checks if
     * it is available.
     *
     * @return bool TRUE if this class is supported by the PHP runtime.
     */
    public static function isSupported()
    {
        return
            PHP_SAPI === "cli" &&
            extension_loaded("pcntl") &&
            function_exists("pcntl_alarm") &&
            function_exists("pcntl_signal") &&
            function_exists("pcntl_signal_dispatch");
    }
}
