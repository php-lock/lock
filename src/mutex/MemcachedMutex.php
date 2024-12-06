<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

/**
 * Memcached based spinlock implementation.
 */
class MemcachedMutex extends SpinlockMutex
{
    /** @var \Memcached */
    private $memcache;

    /**
     * Sets the lock's name and the connected Memcached API.
     *
     * The Memcached API needs to have at least one server in its pool. I.e.
     * it has to be added with Memcached::addServer().
     *
     * @param string $name    The lock name
     * @param float  $timeout The timeout in seconds a lock expires
     *
     * @throws \LengthException The timeout must be greater than 0
     */
    public function __construct(string $name, \Memcached $memcache, float $timeout = 3)
    {
        parent::__construct($name, $timeout);

        $this->memcache = $memcache;
    }

    #[\Override]
    protected function acquire(string $key, float $expire): bool
    {
        // memcached supports only integer expire
        // https://github.com/memcached/memcached/wiki/Commands#standard-protocol
        $expireInt = (int) ceil($expire);

        return $this->memcache->add($key, true, $expireInt);
    }

    #[\Override]
    protected function release(string $key): bool
    {
        return $this->memcache->delete($key);
    }
}
