<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;

/**
 * CAS based mutex implementation.
 *
 * This mutex doesn't lock at all. It implements the compare-and-swap
 * approach. I.e. it will repeat excuting the code block until it wasn't
 * modified in between. Use this only when you know that concurrency is
 * a rare event.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 */
class CASMutex extends Mutex
{
    
    /**
     * @var int The timeout in seconds.
     */
    private $timeout;
    
    /**
     * @var bool The state of the last CAS operation.
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
     * Notifies the Mutex about a successfull CAS operation.
     */
    public function notify()
    {
        $this->successful = true;
    }
    
    /**
     * Repeats executing a code until a compare-and-swap operation was succesful.
     *
     * The code has to be designed in a way that it can be repeated without any
     * side effects. When the CAS operation was successful it should notify
     * this mutex by calling {@link CASMutex::notify()}. I.e. the only side effects
     * of the code may happen after a successful CAS operation. The CAS
     * operation itself is a valid side effect as well.
     *
     * If the code throws an exception it will stop repeating the execution.
     *
     * @param callable $block The synchronized execution block.
     * @return mixed The return value of the execution block.
     *
     * @throws \Exception The execution block threw an exception.
     * @throws LockAcquireException The timeout was reached.
     */
    public function synchronized(callable $block)
    {
        $this->successful = false;
        $minWait = 100;
        $maxWait = $this->timeout * 1000000;
        $waited  = 0;
        for ($i = 0; !$this->successful && $waited <= $maxWait; $i++) {
            $result = call_user_func($block);
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
            throw new LockAcquireException("Timeout");

        }
        return $result;
    }
}
