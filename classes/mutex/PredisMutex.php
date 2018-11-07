<?php

namespace malkusch\lock\mutex;

use Predis\ClientInterface;
use Predis\PredisException;
use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;

/**
 * Mutex based on the Redlock algorithm using the Predis API.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @license WTFPL
 *
 * @link http://redis.io/topics/distlock
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 */
class PredisMutex extends RedisMutex
{
    
    /**
     * Sets the Redis connections.
     *
     * @param ClientInterface[] $clients The Redis clients.
     * @param string   $name    The lock name.
     * @param int      $timeout The time in seconds a lock expires, default is 3.
     *
     * @throws \LengthException The timeout must be greater than 0.
     */
    public function __construct(array $clients, string $name, int $timeout = 3)
    {
        parent::__construct($clients, $name, $timeout);
    }

    /**
     * @throws LockAcquireException
     */
    protected function add($redisAPI, string $key, string $value, int $expire): bool
    {
        /** @var ClientInterface $redisAPI */
        try {
            return $redisAPI->set($key, $value, "EX", $expire, "NX") !== null;
        } catch (PredisException $e) {
            $message = sprintf(
                "Failed to acquire lock for key '%s'",
                $key
            );
            throw new LockAcquireException($message, 0, $e);
        }
    }

    /**
     * @throws LockReleaseException
     */
    protected function evalScript($client, string $script, int $numkeys, array $arguments)
    {
        /** @var ClientInterface $client */
        try {
            return $client->eval($script, $numkeys, ...$arguments);
        } catch (PredisException $e) {
            throw new LockReleaseException("Failed to release lock", 0, $e);
        }
    }
}
