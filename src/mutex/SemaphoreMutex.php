<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

use InvalidArgumentException;
use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;

/**
 * System V IPC based mutex implementation.
 */
class SemaphoreMutex extends LockMutex
{
    /**
     * @var \SysvSemaphore|resource the semaphore id
     */
    private $semaphore;

    /**
     * Sets the semaphore id.
     *
     * Use {@link sem_get()} to create the semaphore id.
     *
     * Example:
     * <code>
     * $semaphore = sem_get(ftok(__FILE__, "a"));
     * $mutex     = new SemaphoreMutex($semaphore);
     * </code>
     *
     * @param \SysvSemaphore|resource $semaphore the semaphore id
     *
     * @throws \InvalidArgumentException the semaphore id is not a valid resource
     */
    public function __construct($semaphore)
    {
        if (!$semaphore instanceof \SysvSemaphore && !is_resource($semaphore)) {
            throw new InvalidArgumentException('The semaphore id is not a valid resource.');
        }
        $this->semaphore = $semaphore;
    }

    /**
     * @internal
     */
    protected function lock(): void
    {
        if (!sem_acquire($this->semaphore)) {
            throw new LockAcquireException('Failed to acquire the Semaphore.');
        }
    }

    /**
     * @internal
     */
    protected function unlock(): void
    {
        if (!sem_release($this->semaphore)) {
            throw new LockReleaseException('Failed to release the Semaphore.');
        }
    }
}
