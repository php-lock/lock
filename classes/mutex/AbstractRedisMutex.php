<?php

namespace malkusch\lock\mutex;

/**
 * Mutex based on the Redlock algorithm.
 *
 * Note: If you're going to use this mutex in a forked process, you have to call
 * {@link seedRandom()} in each instance.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @license WTFPL
 *
 * @link http://redis.io/topics/distlock
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 */
abstract class AbstractRedisMutex extends SpinlockMutex
{
    
    /**
     * @var string The random value token for key identification.
     */
    private $token;

    public function __construct($name, $timeout = 3)
    {
        parent::__construct($name, $timeout);

        $this->seedRandom();
    }
    
    /**
     * Seeds the random number generator.
     *
     * Normally you don't need to seed, as this happens automatically. But
     * if you experience a {@link LockReleaseException} this might come
     * from identically created random tokens. In this case you could seed
     * from /dev/urandom.
     *
     * @param int|null $seed The optional seed.
     */
    public function seedRandom($seed = null)
    {
        is_null($seed) ? srand() : srand($seed);
    }
    
    /**
     * @SuppressWarnings(PHPMD)
     * @internal
     */
    protected function acquire($key, $expire)
    {
        // 1. This differs from the specification to avoid an overflow on 32-BIT systems.
        $time = microtime(true);
        
        // 2.
        $this->token = rand();
        $acquired = 0;
        foreach ($this->getConnections() as $connection) {
            if ($this->add($connection, $key, $this->token, $expire)) {
                $acquired++;
            }
        }
        
        // 3.
        $elapsedTime = microtime(true) - $time;
        $isAcquired  = $this->isMajority($acquired) && $elapsedTime <= $expire;
        
        if ($isAcquired) {
            // 4.
            return true;
            
        } else {
            // 5.
            $this->release($key);
            return false;
        }
    }
    
    /**
     * @internal
     */
    protected function release($key)
    {
        /*
         * Question for Redis: Why do I have to try to delete also keys
         * which I haven't acquired? I do observe collisions of the random
         * token, which results in releasing the wrong key.
         */

        $script = '
            if redis.call("get",KEYS[1]) == ARGV[1] then
                return redis.call("del",KEYS[1])
            else
                return 0
            end
        ';
        $released = 0;
        foreach ($this->getConnections() as $connection) {
            if ($this->evalScript($connection, $script, 1, [$key, $this->token])) {
                $released++;
            }
        }
        return $this->isMajority($released);
    }
    
    /**
     * Returns if a count is the majority of all servers.
     *
     * @param int $count The count.
     * @return bool True if the count is the majority.
     */
    private function isMajority($count)
    {
        return $count > count($this->getConnections()) / 2;
    }
    
    /**
     * @return array The list of connected Redis APIs.
     * @internal
     */
    abstract protected function getConnections();

    /**
     * Sets the key only if such key doesn't exist at the server yet.
     *
     * @param mixed  $connection The connected Redis API.
     * @param string $key The key.
     * @param string $value The value.
     * @param int    $expire The TTL seconds.
     *
     * @return bool True, if the key was set.
     * @internal
     */
    abstract protected function add($connection, $key, $value, $expire);

    /**
     * @param mixed  $connection The connected Redis API.
     * @param string $script     The Lua script.
     * @param int    $numkeys    The number of arguments that represent Redis key names.
     * @param array  $arguments  Keys and values.
     *
     * @return mixed The script result, or false if executing failed.
     * @internal
     */
    abstract protected function evalScript($connection, $script, $numkeys, array $arguments);
}
