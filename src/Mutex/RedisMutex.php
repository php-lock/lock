<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Util\LockUtil;
use Predis\ClientInterface as PredisClientInterface;
use Predis\PredisException;

/**
 * Distributed mutex based on the Redlock algorithm supporting the phpredis extension and Predis API.
 *
 * @phpstan-type TClient \Redis|\RedisCluster|PredisClientInterface
 *
 * @extends AbstractRedlockMutex<TClient>
 *
 * @see http://redis.io/topics/distlock
 */
class RedisMutex extends AbstractRedlockMutex
{
    /**
     * @param TClient $client
     *
     * @phpstan-assert-if-true \Redis|\RedisCluster $client
     */
    private function isClientPHPRedis(object $client): bool
    {
        $res = $client instanceof \Redis || $client instanceof \RedisCluster;

        \assert($res === !$client instanceof PredisClientInterface);

        return $res;
    }

    private function makeRedisExpireTimeoutMillis(float $value): int
    {
        $res = LockUtil::getInstance()->castFloatToInt(ceil($value * 1000));

        // workaround https://github.com/redis/docs/blob/377fb96c09/content/commands/expire/index.md?plain=1#L224
        if ($res < \PHP_INT_MAX) {
            ++$res;
        }

        // workaround time + timeout math overflow
        if ($res < 0) {
            $res = 0;
        } elseif (\PHP_INT_SIZE >= 6) {
            $thousandYearsMillis = (int) (1000 * 365.25 * 24 * 60 * 60 * 1000);
            if ($res > $thousandYearsMillis) {
                $res = $thousandYearsMillis;
            }
        }

        return $res;
    }

    /**
     * @throws LockAcquireException
     */
    #[\Override]
    protected function add(object $client, string $key, string $value, float $expire): bool
    {
        $expireTimeoutMillis = $this->makeRedisExpireTimeoutMillis($expire);

        if ($this->isClientPHPRedis($client)) {
            try {
                //  Will set the key, if it doesn't exist, with a ttl of $expire seconds
                return $client->set($key, $value, ['nx', 'px' => $expireTimeoutMillis]);
            } catch (\RedisException $e) {
                $message = sprintf(
                    'Failed to acquire lock for key \'%s\'',
                    $key
                );

                throw new LockAcquireException($message, 0, $e);
            }
        } else {
            try {
                return $client->set($key, $value, 'PX', $expireTimeoutMillis, 'NX') !== null;
            } catch (PredisException $e) {
                $message = sprintf(
                    'Failed to acquire lock for key \'%s\'',
                    $key
                );

                throw new LockAcquireException($message, 0, $e);
            }
        }
    }

    #[\Override]
    protected function evalScript(object $client, string $luaScript, array $keys, array $arguments)
    {
        if ($this->isClientPHPRedis($client)) {
            $arguments = array_map(function ($v) use ($client) {
                /*
                 * If a serialization mode such as "php" or "igbinary" is enabled, the arguments must be
                 * serialized by us, because phpredis does not do this for the eval command.
                 *
                 * The keys must not be serialized.
                 */
                $v = $client->_serialize($v);

                /*
                 * If LZF compression is enabled for the redis connection and the runtime has the LZF
                 * extension installed, compress the arguments as the final step.
                 */
                if ($this->isLzfCompressionEnabled($client)) {
                    $v = lzf_compress($v);
                }

                return $v;
            }, $arguments);

            try {
                return $client->eval($luaScript, [...$keys, ...$arguments], count($keys));
            } catch (\RedisException $e) {
                throw new LockReleaseException('Failed to release lock', 0, $e);
            }
        } else {
            try {
                return $client->eval($luaScript, count($keys), ...[...$keys, ...$arguments]);
            } catch (PredisException $e) {
                throw new LockReleaseException('Failed to release lock', 0, $e);
            }
        }
    }

    /**
     * @param \Redis|\RedisCluster $client
     */
    private function isLzfCompressionEnabled(object $client): bool
    {
        if (!\defined('Redis::COMPRESSION_LZF')) {
            return false;
        }

        return $client->getOption(\Redis::OPT_COMPRESSION) === \Redis::COMPRESSION_LZF;
    }
}
