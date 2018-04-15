<?php

namespace malkusch\lock\mutex;

use Redis;
use RedisException;
use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;

/**
 * Mutex based on the Redlock algorithm using the phpredis extension.
 *
 * This implementation requires at least phpredis-2.2.4.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @license WTFPL
 *
 * @link http://redis.io/topics/distlock
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
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
        /** @var Redis $redis */
        try {
            //  Will set the key, if it doesn't exist, with a ttl of $expire seconds
            return $redis->set($key, $value, ["nx", "ex" => $expire]);
        } catch (RedisException $e) {
            $message = sprintf(
                "Failed to acquire lock for key '%s' at %s",
                $key,
                $this->getRedisIdentifier($redis)
            );
            throw new LockAcquireException($message, 0, $e);
        }
    }

    /**
     * @internal
     */
    protected function evalScript($redis, $script, $numkeys, array $arguments)
    {
        /** @var Redis $redis */
        try {
            return $redis->eval($script, $arguments, $numkeys);
        } catch (RedisException $e) {
            $message = sprintf(
                "Failed to release lock at %s",
                $this->getRedisIdentifier($redis)
            );
            throw new LockReleaseException($message, 0, $e);
        }
    }

    /**
     * @internal
     */
    protected function getRedisIdentifier($redis)
    {
        /** @var Redis $redis */
        return sprintf("redis://%s:%d?database=%s", $redis->getHost(), $redis->getPort(), $redis->getDBNum());
    }
}
