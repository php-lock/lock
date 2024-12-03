<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

/**
 * Mutex based on the Redlock algorithm.
 *
 * @see http://redis.io/topics/distlock
 */
abstract class RedisMutex extends SpinlockMutex implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var string the random value token for key identification
     */
    private $token;

    /**
     * @var array the Redis APIs
     */
    private $redisAPIs;

    /**
     * Sets the Redis APIs.
     *
     * @param array  $redisAPIs the Redis APIs
     * @param string $name      the lock name
     * @param float  $timeout   the time in seconds a lock expires, default is 3
     *
     * @throws \LengthException the timeout must be greater than 0
     */
    public function __construct(array $redisAPIs, string $name, float $timeout = 3)
    {
        parent::__construct($name, $timeout);

        $this->redisAPIs = $redisAPIs;
        $this->logger = new NullLogger();
    }

    protected function acquire(string $key, float $expire): bool
    {
        // 1. This differs from the specification to avoid an overflow on 32-Bit systems.
        $time = microtime(true);

        // 2.
        $acquired = 0;
        $errored = 0;
        $this->token = bin2hex(random_bytes(16));
        $exception = null;
        foreach ($this->redisAPIs as $index => $redisAPI) {
            try {
                if ($this->add($redisAPI, $key, $this->token, $expire)) {
                    $acquired++;
                }
            } catch (LockAcquireException $exception) {
                // todo if there is only one redis server, throw immediately.
                $context = [
                    'key' => $key,
                    'index' => $index,
                    'token' => $this->token,
                    'exception' => $exception,
                ];
                $this->logger->warning('Could not set {key} = {token} at server #{index}.', $context);

                $errored++;
            }
        }

        // 3.
        $elapsedTime = microtime(true) - $time;
        $isAcquired = $this->isMajority($acquired) && $elapsedTime <= $expire;

        if ($isAcquired) {
            // 4.
            return true;
        }

        // 5.
        $this->release($key);

        // In addition to RedLock it's an exception if too many servers fail.
        if (!$this->isMajority(count($this->redisAPIs) - $errored)) {
            assert($exception !== null); // The last exception for some context.

            throw new LockAcquireException(
                "It's not possible to acquire a lock because at least half of the Redis server are not available.",
                LockAcquireException::REDIS_NOT_ENOUGH_SERVERS,
                $exception
            );
        }

        return false;
    }

    protected function release(string $key): bool
    {
        /*
         * All Redis commands must be analyzed before execution to determine which keys the command will operate on. In
         * order for this to be true for EVAL, keys must be passed explicitly.
         *
         * @link https://redis.io/commands/set
         */
        $script = 'if redis.call("get",KEYS[1]) == ARGV[1] then
                return redis.call("del",KEYS[1])
            else
                return 0
            end
        ';
        $released = 0;
        foreach ($this->redisAPIs as $index => $redisAPI) {
            try {
                if ($this->evalScript($redisAPI, $script, 1, [$key, $this->token])) {
                    $released++;
                }
            } catch (LockReleaseException $e) {
                // todo throw if there is only one redis server
                $context = [
                    'key' => $key,
                    'index' => $index,
                    'token' => $this->token,
                    'exception' => $e,
                ];
                $this->logger->warning('Could not unset {key} = {token} at server #{index}.', $context);
            }
        }

        return $this->isMajority($released);
    }

    /**
     * Returns if a count is the majority of all servers.
     *
     * @param int $count the count
     *
     * @return bool true if the count is the majority
     */
    private function isMajority(int $count): bool
    {
        return $count > count($this->redisAPIs) / 2;
    }

    /**
     * Sets the key only if such key doesn't exist at the server yet.
     *
     * @param mixed  $redisAPI the connected Redis API
     * @param string $key      the key
     * @param string $value    the value
     * @param float  $expire   the TTL seconds
     *
     * @return bool true, if the key was set
     */
    abstract protected function add($redisAPI, string $key, string $value, float $expire): bool;

    /**
     * @param mixed  $redisAPI  the connected Redis API
     * @param string $script    the Lua script
     * @param int    $numkeys   the number of values in $arguments that represent Redis key names
     * @param array  $arguments keys and values
     *
     * @return mixed the script result, or false if executing failed
     *
     * @throws LockReleaseException an unexpected error happened
     */
    abstract protected function evalScript($redisAPI, string $script, int $numkeys, array $arguments);
}
