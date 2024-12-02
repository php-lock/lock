<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

/**
 * This mutex doesn't lock at all.
 *
 * Synchronization is not provided! This mutex is just implementing the
 * interface without locking.
 */
class NoMutex extends Mutex
{
    public function synchronized(callable $code)
    {
        return $code();
    }
}
