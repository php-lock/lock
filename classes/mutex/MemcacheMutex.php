<?php

namespace malkusch\lock\mutex;

use Memcache;

/**
 * Memcache based spinlock implementation.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 * @deprecated 1.0.0 Use MemcachedMutex together with ext-memcached.
 */
class MemcacheMutex extends SpinlockMutex
{
    
    /**
     * @var Memcache The connected Memcache API.
     */
    private $memcache;
    
    /**
     * The memcache key prefix.
     * @internal
     */
    const PREFIX = "lock_";
    
    /**
     * Sets the lock's name and the connected Memcache API.
     *
     * The Memcache API needs to be connected to a server.
     * I.e. Memcache::connect() was already called.
     *
     * @param string   $name     The lock name.
     * @param Memcache $memcache The connected Memcache API.
     * @param int      $timeout  The time in seconds a lock expires, default is 3.
     *
     * @throws \LengthException The timeout must be greater than 0.
     */
    public function __construct($name, Memcache $memcache, $timeout = 3)
    {
        parent::__construct($name, $timeout);
        trigger_error("MemcacheMutex has been deprecated in favour of MemcachedMutex.", E_USER_DEPRECATED);
        
        $this->memcache = $memcache;
    }
    
    /**
     * @internal
     */
    protected function acquire($key, $expire)
    {
        return $this->memcache->add($key, true, 0, $expire);
    }

    /**
     * @internal
     */
    protected function release($key)
    {
        return $this->memcache->delete($key);
    }
}
