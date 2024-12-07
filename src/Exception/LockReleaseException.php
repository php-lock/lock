<?php

declare(strict_types=1);

namespace Malkusch\Lock\Exception;

/**
 * Failed to release the lock.
 *
 * Take this exception very serious.
 *
 * Failing to release a lock might have the potential to introduce deadlocks. Also the
 * critical code was executed i.e. side effects may have happened.
 */
class LockReleaseException extends MutexException
{
    /** @var mixed */
    private $codeResult;

    private ?\Throwable $codeException = null;

    /**
     * Gets the result that has been returned during the critical code execution.
     *
     * @return mixed The return value of the executed code block
     */
    public function getCodeResult()
    {
        return $this->codeResult;
    }

    /**
     * @param mixed $codeResult
     *
     * @return $this
     */
    public function setCodeResult($codeResult): self
    {
        $this->codeResult = $codeResult;

        return $this;
    }

    /**
     * Gets the exception that has happened during the synchronized code execution.
     */
    public function getCodeException(): ?\Throwable
    {
        return $this->codeException;
    }

    /**
     * @return $this
     */
    public function setCodeException(\Throwable $codeException): self
    {
        $this->codeException = $codeException;

        return $this;
    }
}
