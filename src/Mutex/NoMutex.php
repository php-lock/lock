<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

/**
 * This mutex doesn't lock at all.
 *
 * Synchronization is not provided! This mutex is just implementing the
 * interface without locking.
 */
class NoMutex extends Mutex
{
    #[\Override]
    public function synchronized(callable $code)
    {
        return $code();
    }
}
