<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Util\LockUtil;

/**
 * Memcached based spinlock implementation.
 */
class MemcachedMutex extends AbstractSpinlockMutex
{
    private \Memcached $memcached;

    /**
     * The Memcached API needs to have at least one server in its pool. I.e.
     * it has to be added with Memcached::addServer().
     *
     * @param float $acquireTimeout In seconds
     */
    public function __construct(string $name, \Memcached $memcached, float $acquireTimeout = 3)
    {
        parent::__construct($name, $acquireTimeout);

        $this->memcached = $memcached;
    }

    #[\Override]
    protected function acquire(string $key, float $expire): bool
    {
        // memcached supports only integer expire
        // https://github.com/memcached/memcached/wiki/Commands#standard-protocol
        $expireInt = LockUtil::getInstance()->castFloatToInt(ceil($expire));

        return $this->memcached->add($key, true, $expireInt);
    }

    #[\Override]
    protected function release(string $key): bool
    {
        return $this->memcached->delete($key);
    }
}
