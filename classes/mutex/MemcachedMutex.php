<?php

namespace malkusch\lock\mutex;

use malkusch\lock\util\Loop;
use malkusch\lock\exception\LockReleaseException;

/**
 * Memcached based spinlock implementation.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 */
class MemcachedMutex extends Mutex
{
    
    /**
     * @var int The timeout in seconds a lock may live.
     */
    private $timeout;
    
    /**
     * @var Loop The loop.
     */
    private $loop;
    
    /**
     * @var \Memcached The connected Memcached API.
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
    const PREFIX = "lockd_";
    
    /**
     * Sets the lock's name and the connected Memcached API.
     *
     * The Memcached API needs to have at least one server in its pool. I.e.
     * it has to be added with Memcached::addServer().
     *
     * @param string     $name     The lock name.
     * @param \Memcached $memcache The connected Memcached API.
     * @param int        $timeout  The time in seconds a lock expires, default is 3.
     *
     * @throws \LengthException The timeout must be greater than 0.
     */
    public function __construct($name, \Memcached $memcache, $timeout = 3)
    {
        $this->memcache = $memcache;
        $this->key      = self::PREFIX . $name;
        $this->timeout  = $timeout;
        $this->loop     = new Loop($this->timeout);
    }

    public function synchronized(callable $code)
    {
        return $this->loop->execute(function () use ($code) {
            if (!$this->memcache->add($this->key, true, $this->timeout + 1)) {
                return;
            }
            $this->loop->end();
            $begin = microtime(true);
            try {
                return call_user_func($code);

            } finally {
                if (microtime(true) - $begin >= $this->timeout) {
                    throw new LockReleaseException(
                        "The lock was released before the code finished execution. Increase the TTL value."
                    );

                }
                
                /*
                 * Worst case would still be one second before the key expires.
                 * This guarantees that we don't delete a wrong key.
                 */
                if (!$this->memcache->delete($this->key)) {
                    throw new LockReleaseException("Could not release lock '$this->key'.");
                }
            }
        });
    }
}
