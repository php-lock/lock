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

    /** @var array<int, AbstractSpinlockMutex> */
    private array $mutexes;

    /** @var list<int> */
    private ?array $lockedMutexIndexes = null;

    /**
     * @param array<int, AbstractSpinlockMutex> $mutexes
     * @param float                             $acquireTimeout In seconds
     * @param float                             $expireTimeout  In seconds
     */
    public function __construct(array $mutexes, float $acquireTimeout = 3, float $expireTimeout = \INF)
    {
        parent::__construct('', $acquireTimeout, $expireTimeout);
        \Closure::bind(function () {
            $this->key = 'distributed';
        }, $this, AbstractSpinlockMutex::class)();

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
        $notAcquired = 0;
        $errored = 0;
        $exception = null;
        foreach ($this->mutexes as $index => $mutex) {
            try {
                if ($this->acquireMutex($mutex, $key, $acquireTimeout - (microtime(true) - $startTs), $expireTimeout)) {
                    $acquiredIndexes[] = $index;
                }
            } catch (LockAcquireException $exception) {
                $this->logger->warning('Could not set {key} = {token} at server #{index}', [
                    'key' => $key,
                    'index' => $index,
                    'exception' => $exception,
                ]);

                ++$errored;
            }

            if (end($acquiredIndexes) !== $index) {
                ++$notAcquired;
            }

            if (!$this->isCountMajority(count($this->mutexes) - $notAcquired)) {
                break;
            }
        }

        // 3.
        $elapsedTime = microtime(true) - $startTs;
        $isAcquired = $this->isCountMajority(count($acquiredIndexes)) && $elapsedTime <= $expireTimeout;

        if ($isAcquired) {
            $this->lockedMutexIndexes = $acquiredIndexes;

            // 4.
            return LockUtil::getInstance()->makeRandomToken();
        }

        // 5.
        foreach ($acquiredIndexes as $index) {
            $this->releaseMutex($this->mutexes[$index], $key, $expireTimeout);
        }

        // In addition to RedLock it's an exception if too many servers fail.
        if (!$this->isCountMajority(count($this->mutexes) - $errored)) {
            assert($exception !== null); // The last exception for some context.

            throw new LockAcquireException(
                'It is not possible to acquire a lock because at least half of the servers are not available',
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

        $expireTimeout = \Closure::bind(fn () => $this->expireTimeout, $this, parent::class)();

        try {
            $released = 0;
            foreach ($this->lockedMutexIndexes as $index) {
                try {
                    if ($this->releaseMutex($this->mutexes[$index], $key, $expireTimeout)) {
                        ++$released;
                    }
                } catch (LockReleaseException $e) {
                    $this->logger->warning('Could not unset {key} = {token} at server #{index}', [
                        'key' => $key,
                        'index' => $index,
                        'exception' => $e,
                    ]);
                }
            }

            return $this->isCountMajority($released);
        } finally {
            $this->lockedMutexIndexes = null;
        }
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
    private function executeMutexWithMinTimeouts(AbstractSpinlockMutex $mutex, \Closure $fx, float $acquireTimeout, float $expireTimeout)
    {
        if ($mutex instanceof AbstractSpinlockWithTokenMutex) {
            return \Closure::bind(static function () use ($mutex, $fx, $expireTimeout) {
                $origExpireTimeout = $mutex->expireTimeout;
                if ($expireTimeout < $mutex->expireTimeout) {
                    $mutex->expireTimeout = $expireTimeout;
                }
                try {
                    return $fx();
                } finally {
                    $mutex->expireTimeout = $origExpireTimeout;
                }
            }, null, parent::class)();
        }

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

    protected function acquireMutex(AbstractSpinlockMutex $mutex, string $key, float $acquireTimeout, float $expireTimeout): bool
    {
        return $this->executeMutexWithMinTimeouts($mutex, static fn () => $mutex->acquire($key), $acquireTimeout, $expireTimeout);
    }

    protected function releaseMutex(AbstractSpinlockMutex $mutex, string $key, float $expireTimeout): bool
    {
        return $this->executeMutexWithMinTimeouts($mutex, static fn () => $mutex->release($key), \INF, $expireTimeout);
    }
}
