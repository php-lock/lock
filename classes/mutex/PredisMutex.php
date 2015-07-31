<?php

namespace malkusch\lock\mutex;

use Predis\Client;
use Predis\PredisException;

/**
 * Mutex based on the Redlock algorithm using the Predis API.
 *
 * Note: If you're going to use this mutex in a forked process, you have to call
 * {@link seedRandom()} in each instance.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @license WTFPL
 *
 * @link http://redis.io/topics/distlock
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 */
class PredisMutex extends RedisMutex
{
    
    /**
     * Sets the Redis connections.
     *
     * @param Client[] $clients The Redis clients.
     * @param string   $name    The lock name.
     * @param int      $timeout The time in seconds a lock expires, default is 3.
     *
     * @throws \LengthException The timeout must be greater than 0.
     */
    public function __construct(array $clients, $name, $timeout = 3)
    {
        parent::__construct($clients, $name, $timeout);
    }
    
    /**
     * @internal
     */
    protected function add($client, $key, $value, $expire)
    {
        try {
            return $client->set($key, $value, "EX", $expire, "NX");
            
        } catch (PredisException $e) {
            return false;
        }
    }

    /**
     * @internal
     */
    protected function evalScript($client, $script, $numkeys, array $arguments)
    {
        try {
            return call_user_func_array(
                [$client, "eval"],
                array_merge([$script, $numkeys], $arguments)
            );
            
        } catch (PredisException $e) {
            return false;
        }
    }
}
