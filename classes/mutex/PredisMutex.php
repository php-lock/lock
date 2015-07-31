<?php

namespace malkusch\lock\mutex;

use Predis\Client;
use Predis\PredisException;
use malkusch\lock\exception\MutexException;

/**
 * Mutex based on the Redlock algorithm using the Predis API.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @license WTFPL
 *
 * @link http://redis.io/topics/distlock
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 */
class PredisMutex extends AbstractRedisMutex
{
    
    /**
     * @var Client[] The Redis connections.
     */
    private $clients;
    
    /**
     * Sets the Redis connections.
     *
     * @param Client[] $clients The Redis clients.
     * @param string   $name    The lock name.
     * @param int      $timeout The time in seconds a lock expires, default is 3.
     *
     * @throws \LengthException The timeout must be greater than 0.
     * @throws MutexException Failed to initialize the random generator.
     */
    public function __construct(array $clients, $name, $timeout = 3)
    {
        parent::__construct($name, $timeout);
        
        $this->clients = $clients;
    }
    
    /**
     * @internal
     */
    protected function add($connection, $key, $value, $expire)
    {
        try {
            return $connection->set($key, $value, "EX", $expire, "NX");
            
        } catch (PredisException $e) {
            return false;
        }
    }

    /**
     * @internal
     */
    protected function evalScript($connection, $script, $numkeys, array $arguments)
    {
        try {
            return call_user_func_array(
                [$connection, "eval"],
                array_merge([$script, $numkeys], $arguments)
            );
            
        } catch (PredisException $e) {
            return false;
        }
    }

    /**
     * @internal
     */
    protected function getConnections()
    {
        return $this->clients;
    }
}
