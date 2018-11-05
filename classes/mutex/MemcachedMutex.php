<?php

namespace malkusch\lock\mutex;

use Memcached;

/**
 * Memcached based spinlock implementation.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
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
     * @param int       $timeout  The time in seconds a lock expires, default is 3.
     *
     * @throws \LengthException The timeout must be greater than 0.
     */
    public function __construct(string $name, Memcached $memcache, int $timeout = 3)
    {
        parent::__construct($name, $timeout);
        
        $this->memcache = $memcache;
    }

    protected function acquire(string $key, int $expire): bool
    {
        return $this->memcache->add($key, true, $expire);
    }

    protected function release(string $key): bool
    {
        return $this->memcache->delete($key);
    }
}
