<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

use malkusch\lock\exception\DeadlineException;
use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use malkusch\lock\exception\TimeoutException;
use malkusch\lock\util\Loop;
use malkusch\lock\util\PcntlTimeout;

/**
 * Flock() based mutex implementation.
 */
class FlockMutex extends LockMutex
{
    public const INFINITE_TIMEOUT = -1.0;

    /**
     * @internal
     */
    const STRATEGY_BLOCK = 1;

    /**
     * @internal
     */
    const STRATEGY_PCNTL = 2;

    /**
     * @internal
     */
    const STRATEGY_BUSY = 3;

    /**
     * @var resource $fileHandle The file handle.
     */
    private $fileHandle;

    /**
     * @var float
     */
    private $timeout;

    /**
     * @var self::STRATEGY_*
     */
    private $strategy;

    /**
     * Sets the file handle.
     *
     * @param resource $fileHandle The file handle.
     * @param float    $timeout
     */
    public function __construct($fileHandle, float $timeout = self::INFINITE_TIMEOUT)
    {
        if (!is_resource($fileHandle)) {
            throw new \InvalidArgumentException('The file handle is not a valid resource.');
        }

        $this->fileHandle = $fileHandle;
        $this->timeout = $timeout;
        $this->strategy = $this->determineLockingStrategy();
    }

    /**
     * @return self::STRATEGY_*
     */
    private function determineLockingStrategy(): int
    {
        if ($this->timeout === self::INFINITE_TIMEOUT) {
            return self::STRATEGY_BLOCK;
        }

        if (PcntlTimeout::isSupported()) {
            return self::STRATEGY_PCNTL;
        }

        return self::STRATEGY_BUSY;
    }

    /**
     * @throws LockAcquireException
     */
    private function lockBlocking(): void
    {
        if (!flock($this->fileHandle, LOCK_EX)) {
            throw new LockAcquireException('Failed to lock the file.');
        }
    }

    /**
     * @throws LockAcquireException
     * @throws TimeoutException
     */
    private function lockPcntl(): void
    {
        $timebox = new PcntlTimeout($this->timeout);

        try {
            $timebox->timeBoxed(
                function (): void {
                    $this->lockBlocking();
                }
            );
        } catch (DeadlineException $e) {
            throw TimeoutException::create($this->timeout);
        }
    }

    /**
     * @throws TimeoutException
     * @throws LockAcquireException
     */
    private function lockBusy()
    {
        $loop = new Loop($this->timeout);
        $loop->execute(function () use ($loop): void {
            if ($this->acquireNonBlockingLock()) {
                $loop->end();
            }
        });
    }

    /**
     * @throws LockAcquireException
     * @return bool
     */
    private function acquireNonBlockingLock(): bool
    {
        if (!flock($this->fileHandle, LOCK_EX|LOCK_NB, $wouldBlock)) {
            if ($wouldBlock) {
                /*
                 * Another process holds the lock.
                 */
                return false;
            }
            throw new LockAcquireException('Failed to lock the file.');
        }

        return true;
    }

    /**
     * @throws LockAcquireException
     * @throws TimeoutException
     */
    protected function lock(): void
    {
        switch ($this->strategy) {
            case self::STRATEGY_BLOCK:
                $this->lockBlocking();

                return;
            case self::STRATEGY_PCNTL:
                $this->lockPcntl();

                return;
            case self::STRATEGY_BUSY:
                $this->lockBusy();

                return;
        }

        throw new \RuntimeException("Unknown strategy '{$this->strategy}'.'");
    }

    /**
     * @throws LockReleaseException
     */
    protected function unlock(): void
    {
        if (!flock($this->fileHandle, LOCK_UN)) {
            throw new LockReleaseException('Failed to unlock the file.');
        }
    }
}
