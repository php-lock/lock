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
 * @template TClient of object
 *
 * @see http://redis.io/topics/distlock
 */
abstract class AbstractRedlockMutex extends AbstractSpinlockMutex implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var string The random value token for key identification */
    private $token;

    /** @var array<int, TClient> */
    private $clients;

    /**
     * Sets the Redis APIs.
     *
     * @param array<int, TClient> $clients
     * @param float               $timeout The timeout in seconds a lock expires
     *
     * @throws \LengthException The timeout must be greater than 0
     */
    public function __construct(array $clients, string $name, float $timeout = 3)
    {
        parent::__construct($name, $timeout);

        $this->clients = $clients;
        $this->logger = new NullLogger();
    }

    #[\Override]
    protected function acquire(string $key, float $expire): bool
    {
        // 1. This differs from the specification to avoid an overflow on 32-Bit systems.
        $time = microtime(true);

        // 2.
        $acquired = 0;
        $errored = 0;
        $this->token = LockUtil::getInstance()->makeRandomToken();
        $exception = null;
        foreach ($this->clients as $index => $client) {
            try {
                if ($this->add($client, $key, $this->token, $expire)) {
                    ++$acquired;
                }
            } catch (LockAcquireException $exception) {
                // todo if there is only one redis server, throw immediately.
                $context = [
                    'key' => $key,
                    'index' => $index,
                    'token' => $this->token,
                    'exception' => $exception,
                ];
                $this->logger->warning('Could not set {key} = {token} at server #{index}', $context);

                ++$errored;
            }
        }

        // 3.
        $elapsedTime = microtime(true) - $time;
        $isAcquired = $this->isMajority($acquired) && $elapsedTime <= $expire;

        if ($isAcquired) {
            // 4.
            return true;
        }

        // 5.
        $this->release($key);

        // In addition to RedLock it's an exception if too many servers fail.
        if (!$this->isMajority(count($this->clients) - $errored)) {
            assert($exception !== null); // The last exception for some context.

            throw new LockAcquireException(
                'It\'s not possible to acquire a lock because at least half of the Redis server are not available',
                LockAcquireException::REDIS_NOT_ENOUGH_SERVERS,
                $exception
            );
        }

        return false;
    }

    #[\Override]
    protected function release(string $key): bool
    {
        /*
         * All Redis commands must be analyzed before execution to determine which keys the command will operate on. In
         * order for this to be true for EVAL, keys must be passed explicitly.
         *
         * @link https://redis.io/commands/set
         */
        $script = 'if redis.call("get", KEYS[1]) == ARGV[1] then
                return redis.call("del", KEYS[1])
            else
                return 0
            end
        ';
        $released = 0;
        foreach ($this->clients as $index => $client) {
            try {
                if ($this->evalScript($client, $script, 1, [$key, $this->token])) {
                    ++$released;
                }
            } catch (LockReleaseException $e) {
                // todo throw if there is only one redis server
                $context = [
                    'key' => $key,
                    'index' => $index,
                    'token' => $this->token,
                    'exception' => $e,
                ];
                $this->logger->warning('Could not unset {key} = {token} at server #{index}', $context);
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
        return $count > count($this->clients) / 2;
    }

    /**
     * Sets the key only if such key doesn't exist at the server yet.
     *
     * @param TClient $client
     * @param float   $expire The TTL seconds
     *
     * @return bool True if the key was set
     */
    abstract protected function add($client, string $key, string $value, float $expire): bool;

    /**
     * @param TClient     $client
     * @param string      $script    The Lua script
     * @param int         $numkeys   The number of values in $arguments that represent Redis key names
     * @param list<mixed> $arguments Keys and values
     *
     * @return mixed The script result, or false if executing failed
     *
     * @throws LockReleaseException An unexpected error happened
     */
    abstract protected function evalScript($client, string $script, int $numkeys, array $arguments);
}
