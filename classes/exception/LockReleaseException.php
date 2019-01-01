<?php

namespace malkusch\lock\exception;

/**
 * Failed to release lock.
 *
 * Take this exception very serious. Failing to release a lock might have
 * the potential to introduce deadlocks. Also the critical code was executed
 * i.e. side effects may have happened.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 */
class LockReleaseException extends MutexException
{

    /**
     * @var mixed
     */
    private $code_result;

    /**
     * @var \Throwable|null
     */
    private $code_exception;

    /**
     * @return mixed The return value of the executed code block.
     */
    public function getCodeResult()
    {
        return $this->code_result;
    }

    /**
     * @param mixed $code_result The return value of the executed code block.
     */
    public function setCodeResult($code_result): void
    {
        $this->code_result = $code_result;
    }

    /**
     * @return \Throwable|null The exception thrown by the code block or null when there was no exception.
     */
    public function getCodeException(): ?\Throwable
    {
        return $this->code_exception;
    }

    /**
     * @param \Throwable $code_exception The exception thrown by the code block.
     */
    public function setCodeException(\Throwable $code_exception): void
    {
        $this->code_exception = $code_exception;
    }
}
