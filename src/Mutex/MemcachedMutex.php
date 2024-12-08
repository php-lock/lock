<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Util\LockUtil;

/**
 * Memcached based spinlock implementation.
 */
class MemcachedMutex extends AbstractSpinlockExpireMutex
{
    private \Memcached $memcached;

    /**
     * The Memcached instance needs to have at least one server in its pool. I.e.
     * it has to be added with Memcached::addServer().
     *
     * @param float $acquireTimeout In seconds
     * @param float $expireTimeout  In seconds
     */
    public function __construct(string $name, \Memcached $memcached, float $acquireTimeout = 3, float $expireTimeout = \INF)
    {
        parent::__construct($name, $acquireTimeout, $expireTimeout);

        $this->memcached = $memcached;
    }

    #[\Override]
    protected function acquireWithToken(string $key, float $expireTimeout)
    {
        // memcached supports only integer expire
        // https://github.com/memcached/memcached/wiki/Commands#standard-protocol
        $expireTimeoutInt = LockUtil::getInstance()->castFloatToInt(ceil($expireTimeout));

        $token = LockUtil::getInstance()->makeRandomToken();

        return $this->memcached->add($key, $token, $expireTimeoutInt)
            ? $token
            : false;
    }

    #[\Override]
    protected function releaseWithToken(string $key, string $token): bool
    {
        // TODO atomic delete only when the remove value matches token

        return $this->memcached->delete($key);
    }
}
