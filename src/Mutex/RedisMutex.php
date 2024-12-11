<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Util\LockUtil;
use Predis\ClientInterface as PredisClientInterface;
use Predis\PredisException;

/**
 * Redis based spinlock implementation supporting the phpredis extension and Predis API.
 *
 * @phpstan-type TClient \Redis|\RedisCluster|PredisClientInterface
 */
class RedisMutex extends AbstractSpinlockWithTokenMutex
{
    /** @var TClient */
    private object $client;

    /**
     * The Redis instance needs to be connected. I.e. Redis::connect() was called already.
     *
     * @param TClient $client
     * @param float   $acquireTimeout In seconds
     * @param float   $expireTimeout  In seconds
     */
    public function __construct(object $client, string $name, float $acquireTimeout = 3, float $expireTimeout = \INF)
    {
        parent::__construct($name, $acquireTimeout, $expireTimeout);

        $this->client = $client;
    }

    /**
     * @phpstan-assert-if-true \Redis|\RedisCluster $this->client
     */
    private function isClientPHPRedis(): bool
    {
        $res = $this->client instanceof \Redis || $this->client instanceof \RedisCluster;

        \assert($res === !$this->client instanceof PredisClientInterface);

        return $res;
    }

    #[\Override]
    protected function acquireWithToken2(string $key, float $expireTimeout) {}

    #[\Override]
    protected function acquireWithToken(string $key, float $expireTimeout)
    {
        $token = LockUtil::getInstance()->makeRandomToken();

        return $this->add($key, $token, $expireTimeout)
            ? $token
            : false;
    }

    #[\Override]
    protected function releaseWithToken(string $key, string $token): bool
    {
        $script = <<<'LUA'
            if redis.call("get", KEYS[1]) == ARGV[1] then
                return redis.call("del", KEYS[1])
            else
                return 0
            end
            LUA;

        return (int) $this->evalScript($script, [$key], [$token]) === 1;
    }

    private function makeRedisExpireTimeoutMillis(float $value): int
    {
        $res = LockUtil::getInstance()->castFloatToInt(ceil($value * 1000));

        // workaround https://github.com/redis/docs/blob/377fb96c09/content/commands/expire/index.md?plain=1#L224
        if ($res > 0 && $res < \PHP_INT_MAX) {
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
     * Sets the key only if such key doesn't exist at the server yet.
     *
     * @return bool True if the key was set
     *
     * @throws LockAcquireException
     */
    protected function add(string $key, string $value, float $expireTimeout): bool
    {
        $expireTimeoutMillis = $this->makeRedisExpireTimeoutMillis($expireTimeout);

        if ($this->isClientPHPRedis()) {
            try {
                //  Will set the key, if it doesn't exist, with a ttl of $expire seconds
                return $this->client->set($key, $value, ['nx', 'px' => $expireTimeoutMillis]);
            } catch (\RedisException $e) {
                $message = sprintf(
                    'Failed to acquire lock for key \'%s\'',
                    $key
                );

                throw new LockAcquireException($message, 0, $e);
            }
        } else {
            try {
                return $this->client->set($key, $value, 'PX', $expireTimeoutMillis, 'NX') !== null;
            } catch (PredisException $e) {
                $message = sprintf(
                    'Failed to acquire lock for key \'%s\'',
                    $key
                );

                throw new LockAcquireException($message, 0, $e);
            }
        }
    }

    /**
     * @param list<string> $keys
     * @param list<mixed>  $arguments
     *
     * @return mixed The script result, or false if executing failed
     *
     * @throws LockReleaseException An unexpected error happened
     */
    protected function evalScript(string $luaScript, array $keys, array $arguments)
    {
        if ($this->isClientPHPRedis()) {
            $arguments = array_map(function ($v) {
                /*
                 * If a serialization mode such as "php" or "igbinary" is enabled, the arguments must be
                 * serialized by us, because phpredis does not do this for the eval command.
                 *
                 * The keys must not be serialized.
                 */
                $v = $this->client->_serialize($v);

                /*
                 * If LZF compression is enabled for the redis connection and the runtime has the LZF
                 * extension installed, compress the arguments as the final step.
                 */
                if ($this->isLzfCompressionEnabled()) {
                    $v = lzf_compress($v);
                }

                return $v;
            }, $arguments);

            try {
                return $this->client->eval($luaScript, [...$keys, ...$arguments], count($keys));
            } catch (\RedisException $e) {
                throw new LockReleaseException('Failed to release lock', 0, $e);
            }
        } else {
            try {
                return $this->client->eval($luaScript, count($keys), ...$keys, ...$arguments);
            } catch (PredisException $e) {
                throw new LockReleaseException('Failed to release lock', 0, $e);
            }
        }
    }

    private function isLzfCompressionEnabled(): bool
    {
        if (!\defined('Redis::COMPRESSION_LZF')) {
            return false;
        }

        return $this->client->getOption(\Redis::OPT_COMPRESSION) === \Redis::COMPRESSION_LZF;
    }
}
