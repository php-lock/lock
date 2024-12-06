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
     * @param ClientInterface[] $clients The Redis clients
     * @param float             $timeout The timeout in seconds a lock expires
     *
     * @throws \LengthException The timeout must be greater than 0
     */
    public function __construct(array $clients, string $name, float $timeout = 3)
    {
        parent::__construct($clients, $name, $timeout);
    }

    /**
     * @param ClientInterface $redisAPI
     *
     * @throws LockAcquireException
     */
    #[\Override]
    protected function add($redisAPI, string $key, string $value, float $expire): bool
    {
        $expireMillis = (int) ceil($expire * 1000);

        try {
            return $redisAPI->set($key, $value, 'PX', $expireMillis, 'NX') !== null;
        } catch (PredisException $e) {
            $message = sprintf(
                'Failed to acquire lock for key \'%s\'',
                $key
            );

            throw new LockAcquireException($message, 0, $e);
        }
    }

    /**
     * @param ClientInterface $client
     */
    #[\Override]
    protected function evalScript($client, string $script, int $numkeys, array $arguments)
    {
        try {
            return $client->eval($script, $numkeys, ...$arguments);
        } catch (PredisException $e) {
            throw new LockReleaseException('Failed to release lock', 0, $e);
        }
    }
}
