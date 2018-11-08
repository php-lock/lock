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
    public function __construct(array $redisAPIs, string $name, int $timeout = 3)
    {
        parent::__construct($redisAPIs, $name, $timeout);
    }

    /**
     * @throws LockAcquireException
     */
    protected function add($redisAPI, string $key, string $value, int $expire): bool
    {
        /** @var Redis $redisAPI */
        try {
            //  Will set the key, if it doesn't exist, with a ttl of $expire seconds
            return $redisAPI->set($key, $value, ["nx", "ex" => $expire]);
        } catch (RedisException $e) {
            $message = sprintf(
                "Failed to acquire lock for key '%s'",
                $key
            );
            throw new LockAcquireException($message, 0, $e);
        }
    }

    protected function evalScript($redis, string $script, int $numkeys, array $arguments)
    {
        /** @var Redis $redis */

        /*
         * If a serializion mode such as "php" or "igbinary" is enabled, the arguments must be serialized but the keys
         * must not.
         *
         * @issue 14
         */
        for ($i = $numkeys, $iMax = \count($arguments); $i < $iMax; $i++) {
            $arguments[$i] = $redis->_serialize($arguments[$i]);
        }

        try {
            return $redis->eval($script, $arguments, $numkeys);
        } catch (RedisException $e) {
            throw new LockReleaseException("Failed to release lock", 0, $e);
        }
    }
}
