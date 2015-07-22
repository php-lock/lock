<?php

namespace malkusch\lock\mutex;

use malkusch\lock\util\Loop;
use malkusch\lock\exception\LockReleaseException;
use malkusch\lock\exception\LockAcquireException;

/**
 * Memcached based mutex implementation.
 *
 * This is a lockfree busy waiting with an exponential back off.
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
            $token = $this->acquireToken();
            if (is_null($token)) {
                $this->initKey();
                $token = $this->acquireToken();
                if (is_null($token)) {
                    throw new LockAcquireException(
                        $this->memcache->getResultMessage(),
                        $this->memcache->getResultCode()
                    );
                }
            }
            
            try {
                return call_user_func($code);

            } finally {
                if ($this->releaseToken($token)) {
                    $this->loop->notify();
                }
            }
        });
    }
    
    /**
     * Acquires a CAS token.
     *
     * @return int|null The CAS token, null if the key doesn't exist yet.
     * @throws LockAcquireException Failed to acquire the CAS token.
     */
    private function acquireToken()
    {
        if ($this->memcache->get($this->key, null, $casToken)) {
            return $casToken;
            
        } elseif ($this->memcache->getResultCode() === \Memcached::RES_NOTFOUND) {
            return null;
            
        }
        throw new LockAcquireException($this->memcache->getResultMessage(), $this->memcache->getResultCode());
    }
    
    /**
     * Releases a CAS token.
     *
     * @param int $casToken The CAS token.
     * @return bool True if the CAS operation was successful.
     * @throws LockReleaseException Failed to release the CAS token.
     */
    private function releaseToken($casToken)
    {
        if ($this->memcache->cas($casToken, $this->key, true, $this->timeout)) {
            return true;

        } elseif ($this->memcache->getResultCode() === \Memcached::RES_DATA_EXISTS) {
            return false;
            
        }
        throw new LockReleaseException($this->memcache->getResultMessage(), $this->memcache->getResultCode());
    }
    
    /**
     * Sets the key initially.
     *
     * @throws LockAcquireException The key could not be set.
     */
    private function initKey()
    {
        if ($this->memcache->add($this->key, true, $this->timeout)) {
            return;
            
        } elseif ($this->memcache->getResultCode() !== \Memcached::RES_NOTSTORED) {
            throw new LockAcquireException($this->memcache->getResultMessage(), $this->memcache->getResultCode());
        }
    }
}
