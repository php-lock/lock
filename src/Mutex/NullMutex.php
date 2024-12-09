<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

/**
 * This mutex does not lock at all.
 */
class NullMutex extends AbstractMutex
{
    #[\Override]
    public function synchronized(callable $code)
    {
        return $code();
    }
}
