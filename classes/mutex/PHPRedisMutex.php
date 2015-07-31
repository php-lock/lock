<?php

namespace malkusch\lock\mutex;

use Redis;
use RedisException;
use malkusch\lock\exception\MutexException;

/**
 * Mutex based on the Redlock algorithm using the phpredis extension.
 *
 * This implementation requires at least phpredis-2.2.4.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @license WTFPL
 *
 * @link http://redis.io/topics/distlock
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 */
class PHPRedisMutex extends AbstractRedisMutex
{
    
    /**
     * @var Redis[] The Redis connections.
     */
    private $connections;
    
    /**
     * Sets the connected Redis APIs.
     *
     * The Redis APIs needs to be connected yet. I.e. Redis::connect() was
     * called already.
     *
     * @param Redis[] $connections The Redis connections.
     * @param string  $name        The lock name.
     * @param int     $timeout     The time in seconds a lock expires, default is 3.
     *
     * @throws \LengthException The timeout must be greater than 0.
     * @throws MutexException Failed to initialize the random generator.
     */
    public function __construct(array $connections, $name, $timeout = 3)
    {
        parent::__construct($name, $timeout);
        
        $this->connections = $connections;
    }
    
    /**
     * @internal
     */
    protected function add($connection, $key, $value, $expire)
    {
        try {
            return $connection->set($key, $value, ["nx", "ex" => $expire]);
            
        } catch (RedisException $e) {
            return false;
        }
    }

    /**
     * @internal
     */
    protected function evalScript($connection, $script, $numkeys, array $arguments)
    {
        try {
            return $connection->eval($script, $arguments, $numkeys);
            
        } catch (RedisException $e) {
            return false;
        }
    }

    /**
     * @internal
     */
    protected function getConnections()
    {
        return $this->connections;
    }
}
