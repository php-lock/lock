<?php

namespace malkusch\lock\util;

use malkusch\lock\exception\TimeoutException;

/**
 * Repeats executing a code until it was successful.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
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
     * @var bool The state of the code.
     */
    private $successful;
    
    /**
     * Sets the timeout.
     *
     * The default is 3 seconds.
     *
     * @param int $timeout The timeout in seconds.
     */
    public function __construct($timeout = 3)
    {
        $this->timeout = $timeout;
    }
    
    /**
     * Notifies about a successfull execution.
     */
    public function notify()
    {
        $this->successful = true;
    }
    
    /**
     * Repeats executing a code until it was succesful.
     *
     * The code has to be designed in a way that it can be repeated without any
     * side effects. When execution was successful it should notify that event
     * by calling {@link Loop::notify()}. I.e. the only side effects
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
        $this->successful = false;
        $minWait = 100;
        $maxWait = $this->timeout * 1000000;
        $waited  = 0;
        for ($i = 0; !$this->successful && $waited <= $maxWait; $i++) {
            $result = call_user_func($code);
            if ($this->successful) {
                break;

            }
            $min    = $minWait * pow(2, $i);
            $max    = $min * 2;
            $usleep = rand($min, $max);
            
            usleep($usleep);
            $waited += $usleep;

        }
        if (!$this->successful) {
            throw new TimeoutException("Timeout of $this->timeout seconds exceeded.");

        }
        return $result;
    }
}
