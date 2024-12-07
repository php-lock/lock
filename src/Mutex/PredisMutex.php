<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockReleaseException;
use Predis\ClientInterface as PredisClientInterface;
use Predis\PredisException;

/**
 * Mutex based on the Redlock algorithm using the Predis API.
 *
 * @phpstan-type TClient PredisClientInterface
 *
 * @extends AbstractRedlockMutex<TClient>
 *
 * @see http://redis.io/topics/distlock
 */
class PredisMutex extends AbstractRedlockMutex
{
    /**
     * Sets the Redis connections.
     *
     * @param array<TClient> $clients The Redis clients
     * @param float          $timeout The timeout in seconds a lock expires
     *
     * @throws \LengthException The timeout must be greater than 0
     */
    public function __construct(array $clients, string $name, float $timeout = 3)
    {
        parent::__construct($clients, $name, $timeout);
    }

    /**
     * @throws LockAcquireException
     */
    #[\Override]
    protected function add($client, string $key, string $value, float $expire): bool
    {
        $expireMillis = (int) ceil($expire * 1000);

        try {
            return $client->set($key, $value, 'PX', $expireMillis, 'NX') !== null;
        } catch (PredisException $e) {
            $message = sprintf(
                'Failed to acquire lock for key \'%s\'',
                $key
            );

            throw new LockAcquireException($message, 0, $e);
        }
    }

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
