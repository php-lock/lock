<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;

/**
 * Mutex based on the Redlock algorithm using the phpredis extension.
 *
 * This implementation requires at least phpredis-4.0.0. If used together with
 * the lzf extension, and phpredis is configured to use lzf compression, at
 * least phpredis-4.3.0 is required! For reason, see github issue link.
 *
 * @see https://github.com/phpredis/phpredis/issues/1477
 * @see http://redis.io/topics/distlock
 */
class PHPRedisMutex extends RedisMutex
{
    /**
     * Sets the connected Redis APIs.
     *
     * The Redis APIs needs to be connected. I.e. Redis::connect() was
     * called already.
     *
     * @param array<\Redis|\RedisCluster> $redisAPIs the Redis connections
     * @param string                      $name      the lock name
     * @param float                       $timeout   The time in seconds a lock expires after. Default is 3 seconds.
     *
     * @throws \LengthException the timeout must be greater than 0
     */
    public function __construct(array $redisAPIs, string $name, float $timeout = 3)
    {
        parent::__construct($redisAPIs, $name, $timeout);
    }

    /**
     * @param \Redis|\RedisCluster $redisAPI the Redis or RedisCluster connection
     *
     * @throws LockAcquireException
     */
    #[\Override] // @phpstan-ignore method.childParameterType
    protected function add($redisAPI, string $key, string $value, float $expire): bool
    {
        $expireMillis = (int) ceil($expire * 1000);

        /** @var \Redis $redisAPI */
        try {
            //  Will set the key, if it doesn't exist, with a ttl of $expire seconds
            return $redisAPI->set($key, $value, ['nx', 'px' => $expireMillis]);
        } catch (\RedisException $e) {
            $message = sprintf(
                'Failed to acquire lock for key \'%s\'',
                $key
            );

            throw new LockAcquireException($message, 0, $e);
        }
    }

    /**
     * @param \Redis|\RedisCluster $redis the Redis or RedisCluster connection
     */
    #[\Override] // @phpstan-ignore method.childParameterType
    protected function evalScript($redis, string $script, int $numkeys, array $arguments)
    {
        for ($i = $numkeys; $i < count($arguments); ++$i) {
            /*
             * If a serialization mode such as "php" or "igbinary" is enabled, the arguments must be
             * serialized by us, because phpredis does not do this for the eval command.
             *
             * The keys must not be serialized.
             */
            $arguments[$i] = $redis->_serialize($arguments[$i]);

            /*
             * If LZF compression is enabled for the redis connection and the runtime has the LZF
             * extension installed, compress the arguments as the final step.
             */
            if ($this->hasLzfCompression($redis)) {
                $arguments[$i] = lzf_compress($arguments[$i]);
            }
        }

        try {
            return $redis->eval($script, $arguments, $numkeys);
        } catch (\RedisException $e) {
            throw new LockReleaseException('Failed to release lock', 0, $e);
        }
    }

    /**
     * Determines if lzf compression is enabled for the given connection.
     *
     * @param \Redis|\RedisCluster $redis the Redis or RedisCluster connection
     *
     * @return bool TRUE if lzf compression is enabled, false otherwise
     */
    private function hasLzfCompression($redis): bool
    {
        if (!\defined('Redis::COMPRESSION_LZF')) {
            return false;
        }

        return $redis->getOption(\Redis::OPT_COMPRESSION) === \Redis::COMPRESSION_LZF;
    }
}
