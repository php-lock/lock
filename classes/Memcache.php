<?php

namespace malkusch\lock;

/**
 * Memcache based mutex implementation.
 *
 * Don't use this unless you have to. This is a busy waiting lock with an
 * exponential back off. The memcache API doesn't allow anything better than
 * this. Prefere using the memcached API together with {@link CAS}.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 */
class Memcache extends Mutex
{
    
    /**
     * @var int The timeout in seconds a lock may live.
     */
    private $timeout;
    
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
     * I.e. Memcache::conneect() was already called.
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
    }

    public function synchronized(callable $block)
    {
        $locked  = false;
        $minWait = 100;
        $maxWait = $this->timeout * 1000000;
        $waited  = 0;
        for ($i = 0; !$locked && $waited <= $maxWait; $i++) {
            $locked = $this->memcache->add($this->key, true, 0, $this->timeout);
            if ($locked) {
                break;

            }
            $min    = $minWait * pow(2, $i);
            $max    = $min * 2;
            $usleep = rand($min, $max);
            
            usleep($usleep);
            $waited += $usleep;

        }
        if (!$locked) {
            throw new MutexException("Timeout.");

        }
        $begin = microtime(true);
        try {
            return call_user_func($block);

        } finally {
            if (microtime(true) - $begin > $this->timeout) {
                throw new MutexException(
                    "The lock was released before the code finished execution. Increase the TTL value."
                );
                
            }
            if (!$this->memcache->delete($this->key)) {
                throw new MutexException("Could not release lock '$this->key'.");
            }
        }
    }
}
