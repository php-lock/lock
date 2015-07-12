<?php

namespace malkusch\lock;

/**
 * CAS based mutex implementation.
 *
 * This mutex doesn't block at all. It implements the compare-and-swap
 * approach. I.e. it will repeat excuting the code block until it wasn't
 * modified in between.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 */
class CAS extends Mutex
{
    
    /**
     * @var bool The state of the last CAS operation.
     */
    private $successful;
    
    /**
     * @var int Amount of execution attempts.
     */
    private $limit;
    
    /**
     * Sets the maximum number of execution attempts.
     *
     * The default limit is 1000 executions.
     *
     * @param int $limit Amount of execution attempts.
     */
    public function __construct($limit = 1000)
    {
        $this->limit = $limit;
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
     * this mutex by calling {@link CAS::notify()}.
     *
     * If the code throws an exception it will stop repeating the execution.
     *
     * @param callable $block The synchronized execution block.
     * @return mixed The return value of the execution block.
     *
     * @throws \Exception The execution block threw an exception.
     * @throws MutexException The code was repeated more than the execution limit.
     */
    public function synchronized(callable $block)
    {
        $this->successful = false;
        for ($i = 0; $i < $this->limit && !$this->successful; $i++) {
            $result = call_user_func($block);

        }
        if (!$this->successful) {
            throw new MutexException("Exceeded CAS limit.");

        }
        return $result;
    }
}
