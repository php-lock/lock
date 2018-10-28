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
    public function __construct(array $clients, $name, $timeout = 3)
    {
        parent::__construct($clients, $name, $timeout);
    }
    
    /**
     * @internal
     */
    protected function add($client, $key, $value, $expire)
    {
        /** @var ClientInterface $client */
        try {
            return $client->set($key, $value, "EX", $expire, "NX");
        } catch (PredisException $e) {
            $message = sprintf(
                "Failed to acquire lock for key '%s' at %s",
                $key,
                $this->getRedisIdentifier($client)
            );
            throw new LockAcquireException($message, 0, $e);
        }
    }

    /**
     * @internal
     */
    protected function evalScript($client, $script, $numkeys, array $arguments)
    {
        /** @var ClientInterface $client */
        try {
            return $client->eval(...array_merge([$script, $numkeys], $arguments));
        } catch (PredisException $e) {
            $message = sprintf(
                "Failed to release lock at %s",
                $this->getRedisIdentifier($client)
            );
            throw new LockReleaseException($message, 0, $e);
        }
    }
    
    /**
     * @internal
     */
    protected function getRedisIdentifier($client)
    {
        /** @var ClientInterface $client */
        return (string) $client->getConnection();
    }
}
