<?php

declare(strict_types=1);

namespace malkusch\lock\exception;

use Throwable;

/**
 * Lock release exception.
 *
 * Failed to release the lock. Take this exception very serious. Failing to
 * release a lock might have the potential to introduce deadlocks. Also the
 * critical code was executed i.e. side effects may have happened.
 */
class LockReleaseException extends MutexException
{
    /**
     * Result that has been returned during the critical code execution.
     *
     * @var mixed
     */
    private $codeResult;

    /**
     * Exception that has happened during the critical code execution.
     *
     * @var \Throwable|null
     */
    private $codeException;

    /**
     * Gets the result that has been returned during the critical code
     * execution.
     *
     * @return mixed the return value of the executed code block
     */
    public function getCodeResult()
    {
        return $this->codeResult;
    }

    /**
     * Sets the result that has been returned during the critical code
     * execution.
     *
     * @param mixed $codeResult the return value of the executed code block
     *
     * @return self current lock release exception instance
     */
    public function setCodeResult($codeResult): self
    {
        $this->codeResult = $codeResult;

        return $this;
    }

    /**
     * Gets the exception that has happened during the synchronized code
     * execution.
     *
     * @return \Throwable|null the exception thrown by the code block or null
     *                         when there has been no exception
     */
    public function getCodeException(): ?Throwable
    {
        return $this->codeException;
    }

    /**
     * Sets the exception that has happened during the critical code
     * execution.
     *
     * @param \Throwable $codeException the exception thrown by the code block
     *
     * @return self current lock release exception instance
     */
    public function setCodeException(Throwable $codeException): self
    {
        $this->codeException = $codeException;

        return $this;
    }
}
