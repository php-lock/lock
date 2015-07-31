<?php

namespace malkusch\lock\mutex;

use Redis;
use RedisException;

/**
 * Mutex based on the Redlock algorithm using the phpredis extension.
 *
 * This implementation requires at least phpredis-2.2.4.
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
class PHPRedisMutex extends RedisMutex
{
    
    /**
     * Sets the connected Redis APIs.
     *
     * The Redis APIs needs to be connected yet. I.e. Redis::connect() was
     * called already.
     *
     * @param Redis[] $redisAPIs The Redis connections.
     * @param string  $name      The lock name.
     * @param int     $timeout   The time in seconds a lock expires, default is 3.
     *
     * @throws \LengthException The timeout must be greater than 0.
     */
    public function __construct(array $redisAPIs, $name, $timeout = 3)
    {
        parent::__construct($redisAPIs, $name, $timeout);
    }
    
    /**
     * @internal
     */
    protected function add($redis, $key, $value, $expire)
    {
        try {
            return $redis->set($key, $value, ["nx", "ex" => $expire]);
            
        } catch (RedisException $e) {
            return false;
        }
    }

    /**
     * @internal
     */
    protected function evalScript($redis, $script, $numkeys, array $arguments)
    {
        try {
            return $redis->eval($script, $arguments, $numkeys);
            
        } catch (RedisException $e) {
            return false;
        }
    }
}
