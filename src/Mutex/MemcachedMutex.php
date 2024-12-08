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
        $token = LockUtil::getInstance()->makeRandomToken();

        return $this->memcached->add($key, $token, $this->makeMemcachedExpireTimeout($expireTimeout))
            ? $token
            : false;
    }

    #[\Override]
    protected function releaseWithToken(string $key, string $token): bool
    {
        // TODO atomic delete only when the remove value matches token

        return $this->memcached->delete($key);
    }

    private function makeMemcachedExpireTimeout(float $value): int
    {
        $res = LockUtil::getInstance()->castFloatToInt(ceil($value));

        // workaround https://github.com/memcached/memcached/issues/307
        if ($res < \PHP_INT_MAX) {
            ++$res;
        }

        // 0 means no expire
        // https://github.com/php/doc-en/blob/af4410a7e1/reference/memcached/expiration.xml#L17
        $res = max(1, $res);
        // >= 30 days means TS instead of TTL
        // https://github.com/php/doc-en/blob/af4410a7e1/reference/memcached/expiration.xml#L12
        if ($res >= 30 * 24 * 60 * 60) {
            $res = 0;
        }

        return $res;
    }
}
