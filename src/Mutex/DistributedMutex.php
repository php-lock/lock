<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Util\LockUtil;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

/**
 * Distributed mutex based on the Redlock algorithm.
 *
 * @see http://redis.io/topics/distlock#the-redlock-algorithm
 */
class DistributedMutex extends AbstractSpinlockWithTokenMutex implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var array<int, AbstractSpinlockWithTokenMutex> */
    private array $mutexes;

    /**
     * The Redis instance needs to be connected. I.e. Redis::connect() was
     * called already.
     *
     * @param array<int, AbstractSpinlockWithTokenMutex> $mutexes
     * @param float                                      $acquireTimeout In seconds
     * @param float                                      $expireTimeout  In seconds
     */
    public function __construct(array $mutexes, float $acquireTimeout = 3, float $expireTimeout = \INF)
    {
        parent::__construct('', $acquireTimeout, $expireTimeout);

        $this->mutexes = $mutexes;
        $this->logger = new NullLogger();
    }

    #[\Override]
    protected function acquireWithToken(string $key, float $expireTimeout)
    {
        $token = LockUtil::getInstance()->makeRandomToken();

        // 1. This differs from the specification to avoid an overflow on 32-Bit systems.
        $startTs = microtime(true);

        // 2.
        $acquired = 0;
        $errored = 0;
        $exception = null;
        foreach ($this->mutexes as $index => $mutex) {
            try {
                if ($mutex->acquireWithToken($key, $expireTimeout)) {
                    ++$acquired;
                }
            } catch (LockAcquireException $exception) {
                // todo if there is only one redis server, throw immediately.
                $this->logger->warning('Could not set {key} = {token} at server #{index}', [
                    'key' => $key,
                    'index' => $index,
                    'token' => $token,
                    'exception' => $exception,
                ]);

                ++$errored;
            }
        }

        // 3.
        $elapsedTime = microtime(true) - $startTs;
        $isAcquired = $this->isMajority($acquired) && $elapsedTime <= $expireTimeout;

        if ($isAcquired) {
            // 4.
            return $token;
        }

        // 5.
        $this->releaseWithToken($key, $token);

        // In addition to RedLock it's an exception if too many servers fail.
        if (!$this->isMajority(count($this->mutexes) - $errored)) {
            assert($exception !== null); // The last exception for some context.

            throw new LockAcquireException(
                'It\'s not possible to acquire a lock because at least half of the Redis server are not available',
                LockAcquireException::CODE_REDLOCK_NOT_ENOUGH_SERVERS,
                $exception
            );
        }

        return false;
    }

    #[\Override]
    protected function releaseWithToken(string $key, string $token): bool
    {
        $released = 0;
        foreach ($this->mutexes as $index => $mutex) {
            try {
                if ($mutex->releaseWithToken($key, $token)) {
                    ++$released;
                }
            } catch (LockReleaseException $e) {
                // todo throw if there is only one redis server
                $this->logger->warning('Could not unset {key} = {token} at server #{index}', [
                    'key' => $key,
                    'index' => $index,
                    'token' => $token,
                    'exception' => $e,
                ]);
            }
        }

        return $this->isMajority($released);
    }

    /**
     * Returns if a count is the majority of all servers.
     *
     * @return bool True if the count is the majority
     */
    private function isMajority(int $count): bool
    {
        return $count > count($this->mutexes) / 2;
    }
}
