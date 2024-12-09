<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockReleaseException;

/**
 * System V IPC based mutex implementation.
 */
class SemaphoreMutex extends AbstractLockMutex
{
    /** @var \SysvSemaphore|resource */
    private $semaphore;

    /**
     * Use {@link sem_get()} to create the semaphore.
     *
     * Example:
     * <code>
     * $semaphore = sem_get(ftok(__FILE__, 'a'));
     * $mutex = new SemaphoreMutex($semaphore);
     * </code>
     *
     * @param \SysvSemaphore|resource $semaphore
     */
    public function __construct($semaphore)
    {
        if (!$semaphore instanceof \SysvSemaphore && !is_resource($semaphore)) {
            throw new \InvalidArgumentException('The semaphore id is not a valid resource');
        }
        $this->semaphore = $semaphore;
    }

    #[\Override]
    protected function lock(): void
    {
        if (!sem_acquire($this->semaphore)) {
            throw new LockAcquireException('Failed to acquire the Semaphore');
        }
    }

    #[\Override]
    protected function unlock(): void
    {
        if (!sem_release($this->semaphore)) {
            throw new LockReleaseException('Failed to release the Semaphore');
        }
    }
}
