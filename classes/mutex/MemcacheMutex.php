<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockReleaseException;

/**
 * Memcache based mutex implementation.
 *
 * Don't use this unless you have to. This is a busy waiting lock with an
 * exponential back off. The memcache API doesn't allow anything better than
 * this. Prefere using the memcached API together with {@link CASMutex}.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 */
class MemcacheMutex extends Mutex
{
    
    /**
     * @var int The timeout in seconds a lock may live.
     */
    private $timeout;
    
    /**
     * @var CASMutex The CASMutex.
     */
    private $casMutex;
    
    /**
     * @var \Memcache The connected memcache API.
     */
    private $memcache;
    
    /**
     * @var string The key for the lock.
     */
    private $key;
    
    /**
     * The memcache key prefix.
     * @internal
     */
    const PREFIX = "lock_";
    
    /**
     * Sets the lock's name and the connected memcache API.
     *
     * The memcache API needs to be connected to a server.
     * I.e. Memcache::connect() was already called.
     *
     * @param string    $name     The lock name.
     * @param \Memcache $memcache The connected memcache API.
     * @param int       $timeout  The time in seconds a lock expires, default is 3.
     */
    public function __construct($name, \Memcache $memcache, $timeout = 3)
    {
        $this->memcache = $memcache;
        $this->key      = self::PREFIX . $name;
        $this->timeout  = $timeout;
        $this->casMutex = new CASMutex($this->timeout);
    }

    public function synchronized(callable $block)
    {
        return $this->casMutex->synchronized(function () use ($block) {
            if (!$this->memcache->add($this->key, true, 0, $this->timeout)) {
                return;
            }
            $this->casMutex->notify();
            $begin = microtime(true);
            try {
                return call_user_func($block);

            } finally {
                if (microtime(true) - $begin > $this->timeout) {
                    throw new LockReleaseException(
                        "The lock was released before the code finished execution. Increase the TTL value."
                    );

                }
                if (!$this->memcache->delete($this->key)) {
                    throw new LockReleaseException("Could not release lock '$this->key'.");
                }
            }
        });
    }
}
