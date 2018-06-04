<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\ExecutionOutsideLockException;
use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use malkusch\lock\exception\TimeoutException;
use malkusch\lock\util\Loop;
use malkusch\lock\util\PcntlTimeout;

/**
 * Flock() based mutex implementation.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 * @see flock()
 */
class FlockMutex extends LockMutex
{
    const INFINITE_TIMEOUT = -1;

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
    const STRATEGY_BUSY  = 3;

    /**
     * @var resource $fileHandle The file handle.
     */
    private $fileHandle;

    /**
     * @var int
     */
    private $timeout;

    /**
     * @var int
     */
    private $strategy;

    /**
     * @var float|null
     */
    private $acquired;

    /**
     * Sets the file handle.
     *
     * @param resource $fileHandle The file handle.
     * @throws \InvalidArgumentException The file handle is not a valid resource.
     */
    public function __construct($fileHandle, $timeout = self::INFINITE_TIMEOUT)
    {
        if (!is_resource($fileHandle)) {
            throw new \InvalidArgumentException("The file handle is not a valid resource.");
        }

        $this->fileHandle = $fileHandle;
        $this->timeout    = $timeout;
        $this->strategy   = $this->determineLockingStrategy();
    }

    private function determineLockingStrategy()
    {
        if ($this->timeout == self::INFINITE_TIMEOUT) {
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
    private function lockBlocking()
    {
        if (!flock($this->fileHandle, LOCK_EX)) {
            throw new LockAcquireException("Failed to lock the file.");
        }
    }

    /**
     * @throws LockAcquireException
     * @throws TimeoutException
     */
    private function lockPcntl()
    {
        $timebox = new PcntlTimeout($this->timeout);

        $timebox->timeBoxed(function () {
            $this->lockBlocking();
        });
    }

    /**
     * @throws TimeoutException
     * @throws LockAcquireException
     */
    private function lockBusy()
    {
        $loop = new Loop($this->timeout);
        $loop->execute(function () use ($loop) {
            if ($this->acquireNonBlockingLock()) {
                $this->acquired = \microtime(true);
                $loop->end();
            }
        });
    }

    /**
     * @return bool
     * @throws LockAcquireException
     */
    private function acquireNonBlockingLock()
    {
        if (!flock($this->fileHandle, LOCK_EX | LOCK_NB, $wouldblock)) {
            if ($wouldblock) {
                /*
                 * Another process holds the lock.
                 */
                return false;
            }
            throw new LockAcquireException("Failed to lock the file.");
        }
        return true;
    }

    /**
     * @throws LockAcquireException
     * @throws TimeoutException
     */
    protected function lock()
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
     * @throws ExecutionOutsideLockException
     */
    protected function unlock()
    {
        if (!flock($this->fileHandle, LOCK_UN)) {
            throw new LockReleaseException("Failed to unlock the file.");
        }

        if ($this->acquired !== null) {
            $elapsed_time = \microtime(true) - $this->acquired;
            if ($elapsed_time > $this->timeout) {
                throw ExecutionOutsideLockException::create($elapsed_time, $this->timeout);
            }
        }
    }
}
