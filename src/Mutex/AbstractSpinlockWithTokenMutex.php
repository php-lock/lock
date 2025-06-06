<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Exception\ExecutionOutsideLockException;

/**
 * Spinlock implementation with expirable resource locking.
 *
 * Lock is acquired with an unique token that is verified when the lock is being released.
 */
abstract class AbstractSpinlockWithTokenMutex extends AbstractSpinlockMutex
{
    /** In seconds */
    private float $expireTimeout;

    private ?float $acquireTs = null;

    /** @var non-falsy-string */
    private ?string $token = null;

    /**
     * @param float $acquireTimeout In seconds
     * @param float $expireTimeout  In seconds
     */
    public function __construct(string $name, float $acquireTimeout = 3, float $expireTimeout = \INF)
    {
        parent::__construct($name, $acquireTimeout);

        $this->expireTimeout = $expireTimeout;
    }

    #[\Override]
    protected function acquire(string $key): bool
    {
        $acquireTs = microtime(true);

        $token = $this->acquireWithToken($key, $this->expireTimeout);

        if ($token === false) {
            return false;
        }

        $this->acquireTs = $acquireTs;
        $this->token = $token;

        return true;
    }

    #[\Override]
    protected function release(string $key): bool
    {
        try {
            return $this->releaseWithToken($key, $this->token);
        } finally {
            try {
                $elapsedTime = microtime(true) - $this->acquireTs;
                if ($elapsedTime >= $this->expireTimeout) {
                    throw ExecutionOutsideLockException::create($elapsedTime, $this->expireTimeout);
                }
            } finally {
                $this->token = null;
                $this->acquireTs = null;
            }
        }
    }

    /**
     * Same as self::acquire() but with expire timeout and token.
     *
     * @param non-falsy-string $key
     * @param float            $expireTimeout In seconds
     *
     * @return non-falsy-string|false
     */
    abstract protected function acquireWithToken(string $key, float $expireTimeout);

    /**
     * Same as self::release() but with expire timeout and token.
     *
     * @param non-falsy-string $key
     * @param non-falsy-string $token
     */
    abstract protected function releaseWithToken(string $key, string $token): bool;
}
