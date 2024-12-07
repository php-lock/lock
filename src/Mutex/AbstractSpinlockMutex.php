<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Exception\ExecutionOutsideLockException;
use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Util\LockUtil;
use Malkusch\Lock\Util\Loop;

/**
 * Spinlock implementation.
 *
 * @internal
 */
abstract class AbstractSpinlockMutex extends AbstractLockMutex
{
    private string $key;

    /** In seconds */
    private float $timeout;

    /** The timestamp when the lock was acquired */
    private ?float $acquiredTs = null;

    /**
     * @param float $timeout In seconds
     */
    public function __construct(string $name, float $timeout = 3)
    {
        $this->key = LockUtil::getInstance()->getKeyPrefix() . ':' . $name;
        $this->timeout = $timeout;
    }

    #[\Override]
    protected function lock(): void
    {
        $loop = new Loop();

        $loop->execute(function () use ($loop): void {
            $this->acquiredTs = microtime(true);

            /*
             * The expiration timeout for the lock is increased by one second
             * to ensure that we delete only our keys. This will prevent the
             * case that this key expires before the timeout, and another process
             * acquires successfully the same key which would then be deleted
             * by this process.
             */
            if ($this->acquire($this->key, $this->timeout + 1)) {
                $loop->end();
            }
        }, $this->timeout);
    }

    #[\Override]
    protected function unlock(): void
    {
        $elapsedTime = microtime(true) - $this->acquiredTs;
        if ($elapsedTime > $this->timeout) {
            throw ExecutionOutsideLockException::create($elapsedTime, $this->timeout);
        }

        /*
         * Worst case would still be one second before the key expires.
         * This guarantees that we don't delete a wrong key.
         */
        if (!$this->release($this->key)) {
            throw new LockReleaseException('Failed to release the lock');
        }
    }

    /**
     * Try to acquire a lock.
     *
     * @param float $expire In seconds
     *
     * @return bool True if the lock was acquired
     *
     * @throws LockAcquireException an unexpected error happened
     */
    abstract protected function acquire(string $key, float $expire): bool;

    /**
     * Try to release a lock.
     *
     * @return bool True if the lock was released
     */
    abstract protected function release(string $key): bool;
}
