<?php

namespace malkusch\lock\tests\classes;

use malkusch\lock\mutex\PHPRedisMutex;

class PHPRedisMutexWithoutSerialize extends PHPRedisMutex
{
    protected function evalScript($redis, $script, $numkeys, array $arguments)
    {
        // The method WITHOUT serialize.
        try {
            return $redis->eval($script, $arguments, $numkeys);
        } catch (\RedisException $e) {
            $message = sprintf(
                "Failed to release lock at %s",
                $this->getRedisIdentifier($redis)
            );
            throw new \malkusch\lock\exception\LockReleaseException($message, 0, $e);
        }
    }
}
