<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Util\DoubleCheckedLocking;

abstract class AbstractMutex implements Mutex
{
    #[\Override]
    public function check(callable $check): DoubleCheckedLocking
    {
        return new DoubleCheckedLocking($this, $check);
    }
}
