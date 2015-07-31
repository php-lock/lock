<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\MutexException;
use RandomLib\Factory;
use RandomLib\Generator;

/**
 * Mutex based on the Redlock algorithm.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @license WTFPL
 * @internal
 *
 * @link http://redis.io/topics/distlock
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 */
abstract class AbstractRedisMutex extends AbstractSpinlockMutex
{
    
    /**
     * @var string The random value token for key identification.
     */
    private $token;
    
    /**
     * @var Generator The random generator;
     */
    private $random;
    
    /**
     * @throws MutexException Failed to initialize the random generator.
     * @throws \LengthException The timeout must be greater than 0.
     */
    public function __construct($name, $timeout = 3)
    {
        parent::__construct($name, $timeout);
        
        try {
            $factory = new Factory();
            $this->random = $factory->getMediumStrengthGenerator();
            
        } catch (\Exception $e) {
            throw new MutexException("Failed to initialize the random generator.", 0, $e);
        }
    }
    
    /**
     * @SuppressWarnings(PHPMD)
     */
    protected function acquire($key, $expire)
    {
        // 1. This differs from the specification to avoid an overflow on 32-BIT systems.
        $time = microtime(true);
        
        // 2.
        try {
            $this->token = $this->random->generateInt();

        } catch (\Exception $e) {
            throw new LockAcquireException("Failed to generate a random token", 0, $e);

        }
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
     */
    abstract protected function add($connection, $key, $value, $expire);

    /**
     * @param mixed  $connection The connected Redis API.
     * @param string $script     The Lua script.
     * @param int    $numkeys    The number of arguments that represent Redis key names.
     * @param array  $arguments  Keys and values.
     *
     * @return mixed The script result, or false if executing failed.
     */
    abstract protected function evalScript($connection, $script, $numkeys, array $arguments);
}
