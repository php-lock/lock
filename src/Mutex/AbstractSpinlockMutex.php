<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Util\LockUtil;
use Malkusch\Lock\Util\Loop;

/**
 * Spinlock implementation.
 */
abstract class AbstractSpinlockMutex extends AbstractLockMutex
{
    /** @var non-falsy-string */
    private string $key;

    /** In seconds */
    private float $acquireTimeout;

    /**
     * @param float $acquireTimeout In seconds
     */
    public function __construct(string $name, float $acquireTimeout = 3)
    {
        $this->key = LockUtil::getInstance()->getKeyPrefix() . ':' . $name;
        $this->acquireTimeout = $acquireTimeout;
    }

    #[\Override]
    protected function lock(): void
    {
        $loop = new Loop();

        $loop->execute(function () use ($loop): void {
            if ($this->acquire($this->key)) {
                $loop->end();
            }
        }, $this->acquireTimeout);
    }

    #[\Override]
    protected function unlock(): void
    {
        if (!$this->release($this->key)) {
            throw new LockReleaseException('Failed to release the lock');
        }
    }

    /**
     * Try to acquire a lock.
     *
     * @param non-falsy-string $key
     *
     * @return bool True if the lock was acquired
     *
     * @throws LockAcquireException An unexpected error happened
     */
    abstract protected function acquire(string $key): bool;

    /**
     * Try to release a lock.
     *
     * @param non-falsy-string $key
     *
     * @return bool True if the lock was released
     */
    abstract protected function release(string $key): bool;
}
