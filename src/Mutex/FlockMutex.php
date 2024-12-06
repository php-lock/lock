<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Exception\DeadlineException;
use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Exception\TimeoutException;
use Malkusch\Lock\Util\Loop;
use Malkusch\Lock\Util\PcntlTimeout;

/**
 * Flock() based mutex implementation.
 */
class FlockMutex extends AbstractLockMutex
{
    public const INFINITE_TIMEOUT = -1.0;

    /**
     * @internal
     */
    public const STRATEGY_BLOCK = 1;

    /**
     * @internal
     */
    public const STRATEGY_PCNTL = 2;

    /**
     * @internal
     */
    public const STRATEGY_BUSY = 3;

    /** @var resource */
    private $fileHandle;

    /** @var float */
    private $timeout;

    /** @var self::STRATEGY_* */
    private $strategy;

    /**
     * Sets the file handle.
     *
     * @param resource $fileHandle
     */
    public function __construct($fileHandle, float $timeout = self::INFINITE_TIMEOUT)
    {
        if (!is_resource($fileHandle)) {
            throw new \InvalidArgumentException('The file handle is not a valid resource');
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
        if (!flock($this->fileHandle, \LOCK_EX)) {
            throw new LockAcquireException('Failed to lock the file');
        }
    }

    /**
     * @throws LockAcquireException
     * @throws TimeoutException
     */
    private function lockPcntl(): void
    {
        $timeoutInt = (int) ceil($this->timeout);

        $timebox = new PcntlTimeout($timeoutInt);

        try {
            $timebox->timeBoxed(
                function (): void {
                    $this->lockBlocking();
                }
            );
        } catch (DeadlineException $e) {
            throw TimeoutException::create($timeoutInt);
        }
    }

    /**
     * @throws TimeoutException
     * @throws LockAcquireException
     */
    private function lockBusy(): void
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
     */
    private function acquireNonBlockingLock(): bool
    {
        if (!flock($this->fileHandle, \LOCK_EX | \LOCK_NB, $wouldBlock)) {
            if ($wouldBlock === 1) {
                // Another process holds the lock.
                return false;
            }

            throw new LockAcquireException('Failed to lock the file');
        }

        return true;
    }

    /**
     * @throws LockAcquireException
     * @throws TimeoutException
     */
    #[\Override]
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

        throw new \RuntimeException('Unknown "' . $this->strategy . '" strategy'); // @phpstan-ignore deadCode.unreachable
    }

    #[\Override]
    protected function unlock(): void
    {
        if (!flock($this->fileHandle, \LOCK_UN)) {
            throw new LockReleaseException('Failed to unlock the file');
        }
    }
}
