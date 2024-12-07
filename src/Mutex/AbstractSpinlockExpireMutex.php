<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

/**
 * Spinlock implementation with expirable resource locking.
 *
 * Lock is acquired with an unique token that is verified when the lock is being released.
 */
abstract class AbstractSpinlockExpireMutex extends AbstractSpinlockMutex
{
    /** @var non-falsy-string */
    private ?string $token = null;

    /** In seconds */
    private float $expireTimeout;

    /**
     * @param float $acquireTimeout In seconds
     * @param float $expireTimeout  In seconds
     */
    public function __construct(string $name, float $acquireTimeout = 3, float $expireTimeout = \PHP_INT_MAX)
    {
        parent::__construct($name, $acquireTimeout);

        $this->expireTimeout = $expireTimeout;
    }

    #[\Override]
    final protected function acquire(string $key): bool
    {
        /*
         * The expiration timeout for the lock is increased by one second
         * to ensure that we delete only our keys. This will prevent the
         * case that this key expires before the timeout, and another process
         * acquires successfully the same key which would then be deleted
         * by this process.
         */
        $res = $this->acquireWithToken($key, $this->expireTimeout + 1);

        if ($res === false) {
            return false;
        }

        \assert(is_string($res) && strlen($res) > 1); // @phpstan-ignore function.alreadyNarrowedType

        $this->token = $res;

        return true;
    }

    #[\Override]
    final protected function release(string $key): bool
    {
        try {
            return $this->releaseWithToken($key, $this->token);
        } finally {
            $this->token = null;
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
