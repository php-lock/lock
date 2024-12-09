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
        $acquireTimeout = \Closure::bind(fn () => $this->acquireTimeout, $this, AbstractSpinlockMutex::class)();

        // 1. This differs from the specification to avoid an overflow on 32-Bit systems.
        $startTs = microtime(true);

        // 2.
        $acquiredIndexes = [];
        $errored = 0;
        $exception = null;
        foreach ($this->mutexes as $index => $mutex) {
            try {
                if ($this->acquireMutex($mutex, $key, $acquireTimeout, $expireTimeout)) {
                    $acquiredIndexes[] = $index;
                }
            } catch (LockAcquireException $exception) {
                // todo if there is only one redis server, throw immediately.
                $this->logger->warning('Could not set {key} = {token} at server #{index}', [
                    'key' => $key,
                    'index' => $index,
                    'exception' => $exception,
                ]);

                ++$errored;
            }
        }

        // 3.
        $elapsedTime = microtime(true) - $startTs;
        $isAcquired = $this->isCountMajority(count($acquiredIndexes)) && $elapsedTime <= $expireTimeout;

        if ($isAcquired) {
            // 4.
            return LockUtil::getInstance()->makeRandomToken();
        }

        // 5.
        foreach ($acquiredIndexes as $index) {
            $this->releaseMutex($this->mutexes[$index], $key, $acquireTimeout);
        }

        // In addition to RedLock it's an exception if too many servers fail.
        if (!$this->isCountMajority(count($this->mutexes) - $errored)) {
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
        unset($token);

        $acquireTimeout = \Closure::bind(fn () => $this->acquireTimeout, $this, AbstractSpinlockMutex::class)();

        $released = 0;
        foreach ($this->mutexes as $index => $mutex) {
            try {
                if ($this->releaseMutex($mutex, $key, $acquireTimeout)) {
                    ++$released;
                }
            } catch (LockReleaseException $e) {
                // todo throw if there is only one redis server
                $this->logger->warning('Could not unset {key} = {token} at server #{index}', [
                    'key' => $key,
                    'index' => $index,
                    'exception' => $e,
                ]);
            }
        }

        return $this->isCountMajority($released);
    }

    /**
     * @return bool True if the count is the majority
     */
    private function isCountMajority(int $count): bool
    {
        return $count > count($this->mutexes) / 2;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $fx
     *
     * @return T
     */
    private function executeMutexWithAcquireTimeout(AbstractSpinlockWithTokenMutex $mutex, \Closure $fx, float $acquireTimeout)
    {
        return \Closure::bind(static function () use ($mutex, $fx, $acquireTimeout) {
            $origAcquireTimeout = $mutex->acquireTimeout;
            if ($acquireTimeout < $mutex->acquireTimeout) {
                $mutex->acquireTimeout = $acquireTimeout;
            }
            try {
                return $fx();
            } finally {
                $mutex->acquireTimeout = $origAcquireTimeout;
            }
        }, null, AbstractSpinlockMutex::class)();
    }

    protected function acquireMutex(AbstractSpinlockWithTokenMutex $mutex, string $key, float $acquireTimeout, float $expireTimeout): bool
    {
        return $this->executeMutexWithAcquireTimeout($mutex, static fn () => $mutex->acquireWithToken($key, $expireTimeout), $acquireTimeout);
    }

    protected function releaseMutex(AbstractSpinlockWithTokenMutex $mutex, string $key, float $acquireTimeout): bool
    {
        return $this->executeMutexWithAcquireTimeout($mutex, static fn () => $mutex->release($key), $acquireTimeout);
    }
}
