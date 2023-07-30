<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

use Memcached;

/**
 * Memcached based spinlock implementation.
 */
class MemcachedMutex extends SpinlockMutex
{
    /**
     * @var Memcached The connected Memcached API.
     */
    private $memcache;

    /**
     * Sets the lock's name and the connected Memcached API.
     *
     * The Memcached API needs to have at least one server in its pool. I.e.
     * it has to be added with Memcached::addServer().
     *
     * @param string    $name     The lock name.
     * @param Memcached $memcache The connected Memcached API.
     * @param float     $timeout  The time in seconds a lock expires, default is 3.
     *
     * @throws \LengthException The timeout must be greater than 0.
     */
    public function __construct(string $name, Memcached $memcache, float $timeout = 3)
    {
        parent::__construct($name, $timeout);

        $this->memcache = $memcache;
    }

    protected function acquire(string $key, float $expire): bool
    {
        return $this->memcache->add($key, true, $expire);
    }

    protected function release(string $key): bool
    {
        return $this->memcache->delete($key);
    }
}
