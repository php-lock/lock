<?php

declare(strict_types=1);

namespace Malkusch\Lock\Exception;

/**
 * Failed to release the lock.
 *
 * Take this exception very serious.
 *
 * This exception implies that the critical code was executed, i.e. side effects may have happened.
 *
 * Failing to release a lock might have the potential to introduce deadlocks.
 */
class LockReleaseException extends MutexException
{
    /** @var mixed */
    private $codeResult;

    private ?\Throwable $codeException = null;

    /**
     * The return value of the executed critical code.
     *
     * @return mixed
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
     * The exception that has happened during the critical code execution.
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
