<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

use malkusch\lock\exception\ExecutionOutsideLockException;
use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use malkusch\lock\util\LockUtil;
use malkusch\lock\util\Loop;

/**
 * Spinlock implementation.
 *
 * @internal
 */
abstract class SpinlockMutex extends LockMutex
{
    /** @var float The timeout in seconds a lock may live */
    private $timeout;

    /** @var Loop */
    private $loop;

    /** @var string */
    private $key;

    /** @var float The timestamp when the lock was acquired */
    private $acquired;

    /**
     * Sets the timeout.
     *
     * @param float $timeout The timeout in seconds a lock expires
     *
     * @throws \LengthException The timeout must be greater than 0
     */
    public function __construct(string $name, float $timeout = 3)
    {
        $this->timeout = $timeout;
        $this->loop = new Loop($this->timeout);
        $this->key = LockUtil::getInstance()->getKeyPrefix() . ':' . $name;
    }

    #[\Override]
    protected function lock(): void
    {
        $this->loop->execute(function (): void {
            $this->acquired = microtime(true);

            /*
             * The expiration timeout for the lock is increased by one second
             * to ensure that we delete only our keys. This will prevent the
             * case that this key expires before the timeout, and another process
             * acquires successfully the same key which would then be deleted
             * by this process.
             */
            if ($this->acquire($this->key, $this->timeout + 1)) {
                $this->loop->end();
            }
        });
    }

    #[\Override]
    protected function unlock(): void
    {
        $elapsed_time = microtime(true) - $this->acquired;
        if ($elapsed_time > $this->timeout) {
            throw ExecutionOutsideLockException::create($elapsed_time, $this->timeout);
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
     * Tries to acquire a lock.
     *
     * @param float $expire The timeout in seconds when a lock expires
     *
     * @return bool True if the lock could be acquired
     *
     * @throws LockAcquireException an unexpected error happened
     */
    abstract protected function acquire(string $key, float $expire): bool;

    /**
     * Tries to release a lock.
     *
     * @return bool True if the lock could be released
     */
    abstract protected function release(string $key): bool;
}
