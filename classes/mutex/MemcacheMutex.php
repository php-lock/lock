<?php

namespace malkusch\lock\mutex;

use malkusch\lock\util\Loop;
use malkusch\lock\exception\LockReleaseException;

/**
 * Memcache based spinlock implementation.
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
     * @var Loop The loop.
     */
    private $loop;
    
    /**
     * @var \Memcache The connected Memcache API.
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
     * Sets the lock's name and the connected Memcache API.
     *
     * The Memcache API needs to be connected to a server.
     * I.e. Memcache::connect() was already called.
     *
     * @param string    $name     The lock name.
     * @param \Memcache $memcache The connected Memcache API.
     * @param int       $timeout  The time in seconds a lock expires, default is 3.
     *
     * @throws \LengthException The timeout must be greater than 0.
     */
    public function __construct($name, \Memcache $memcache, $timeout = 3)
    {
        $this->memcache = $memcache;
        $this->key      = self::PREFIX . $name;
        $this->timeout  = $timeout;
        $this->loop     = new Loop($this->timeout);
    }

    public function synchronized(callable $code)
    {
        return $this->loop->execute(function () use ($code) {
            if (!$this->memcache->add($this->key, true, 0, $this->timeout + 1)) {
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
