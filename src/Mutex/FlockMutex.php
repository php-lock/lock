<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Exception\DeadlineException;
use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Util\LockUtil;
use Malkusch\Lock\Util\Loop;
use Malkusch\Lock\Util\PcntlTimeout;

/**
 * Flock() based mutex implementation.
 */
class FlockMutex extends AbstractLockMutex
{
    private const STRATEGY_BLOCK = 'block';
    private const STRATEGY_PCNTL = 'pcntl';
    private const STRATEGY_LOOP = 'loop';

    /** @var resource */
    private $fileHandle;

    /** In seconds */
    private float $acquireTimeout;

    /** @var self::STRATEGY_* */
    private $strategy;

    /**
     * @param resource $fileHandle
     * @param float    $acquireTimeout In seconds
     */
    public function __construct($fileHandle, float $acquireTimeout = \INF)
    {
        if (!is_resource($fileHandle)) {
            throw new \InvalidArgumentException('The file handle is not a valid resource');
        }

        $this->fileHandle = $fileHandle;
        $this->acquireTimeout = $acquireTimeout;
        $this->strategy = $this->determineLockingStrategy();
    }

    /**
     * @return self::STRATEGY_*
     */
    private function determineLockingStrategy(): string
    {
        if ($this->acquireTimeout > 100 * 365.25 * 24 * 60 * 60) { // 100 years
            return self::STRATEGY_BLOCK;
        }

        if (PcntlTimeout::isSupported()) {
            return self::STRATEGY_PCNTL;
        }

        return self::STRATEGY_LOOP;
    }

    private function lockBlocking(): void
    {
        if (!flock($this->fileHandle, \LOCK_EX)) {
            throw new LockAcquireException('Failed to lock the file');
        }
    }

    private function lockPcntl(): void
    {
        $acquireTimeoutInt = LockUtil::getInstance()->castFloatToInt(ceil($this->acquireTimeout));

        $timebox = new PcntlTimeout($acquireTimeoutInt);

        try {
            $timebox->timeBoxed(function (): void {
                $this->lockBlocking();
            });
        } catch (DeadlineException $e) {
            throw LockAcquireTimeoutException::create($acquireTimeoutInt);
        }
    }

    private function lockBusy(): void
    {
        $loop = new Loop();

        $loop->execute(function () use ($loop): void {
            if ($this->acquireNonBlockingLock()) {
                $loop->end();
            }
        }, $this->acquireTimeout);
    }

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
            case self::STRATEGY_LOOP:
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
