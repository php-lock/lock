<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use Predis\ClientInterface;
use Predis\PredisException;

/**
 * Mutex based on the Redlock algorithm using the Predis API.
 *
 * @see http://redis.io/topics/distlock
 */
class PredisMutex extends RedisMutex
{
    /**
     * Sets the Redis connections.
     *
     * @param ClientInterface[] $clients the Redis clients
     * @param string            $name    the lock name
     * @param float             $timeout the time in seconds a lock expires, default is 3
     *
     * @throws \LengthException the timeout must be greater than 0
     */
    public function __construct(array $clients, string $name, float $timeout = 3)
    {
        parent::__construct($clients, $name, $timeout);
    }

    /**
     * @throws LockAcquireException
     */
    #[\Override]
    protected function add($redisAPI, string $key, string $value, float $expire): bool
    {
        $expireMillis = (int) ceil($expire * 1000);

        /** @var ClientInterface $redisAPI */
        try {
            return $redisAPI->set($key, $value, 'PX', $expireMillis, 'NX') !== null;
        } catch (PredisException $e) {
            $message = sprintf(
                "Failed to acquire lock for key '%s'",
                $key
            );

            throw new LockAcquireException($message, 0, $e);
        }
    }

    #[\Override]
    protected function evalScript($client, string $script, int $numkeys, array $arguments)
    {
        /** @var ClientInterface $client */
        try {
            return $client->eval($script, $numkeys, ...$arguments);
        } catch (PredisException $e) {
            throw new LockReleaseException('Failed to release lock', 0, $e);
        }
    }
}
