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
abstract class AbstractRedlockMutex extends AbstractSpinlockExpireMutex implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var array<int, TClient> */
    private array $clients;

    /**
     * The Redis instance needs to be connected. I.e. Redis::connect() was
     * called already.
     *
     * @param array<int, TClient> $clients
     * @param float               $acquireTimeout In seconds
     * @param float               $expireTimeout  In seconds
     */
    public function __construct(array $clients, string $name, float $acquireTimeout = 3, float $expireTimeout = \PHP_INT_MAX)
    {
        parent::__construct($name, $acquireTimeout, $expireTimeout);

        $this->clients = $clients;
        $this->logger = new NullLogger();
    }

    #[\Override]
    protected function acquireWithToken(string $key, float $expireTimeout)
    {
        // 1. This differs from the specification to avoid an overflow on 32-Bit systems.
        $startTs = microtime(true);

        // 2.
        $acquired = 0;
        $errored = 0;
        $token = LockUtil::getInstance()->makeRandomToken();
        $exception = null;
        foreach ($this->clients as $index => $client) {
            try {
                if ($this->add($client, $key, $token, $expireTimeout)) {
                    ++$acquired;
                }
            } catch (LockAcquireException $exception) {
                // todo if there is only one redis server, throw immediately.
                $context = [
                    'key' => $key,
                    'index' => $index,
                    'token' => $token,
                    'exception' => $exception,
                ];
                $this->logger->warning('Could not set {key} = {token} at server #{index}', $context);

                ++$errored;
            }
        }

        // 3.
        $elapsedTime = microtime(true) - $startTs;
        $isAcquired = $this->isMajority($acquired) && $elapsedTime <= $expireTimeout;

        if ($isAcquired) {
            // 4.
            return $token;
        }

        // 5.
        $this->releaseWithToken($key, $token);

        // In addition to RedLock it's an exception if too many servers fail.
        if (!$this->isMajority(count($this->clients) - $errored)) {
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
        /*
         * All Redis commands must be analyzed before execution to determine which keys the command will operate on. In
         * order for this to be true for EVAL, keys must be passed explicitly.
         *
         * @link https://redis.io/commands/set
         */
        $script = <<<'EOD'
            if redis.call("get", KEYS[1]) == ARGV[1] then
                return redis.call("del", KEYS[1])
            else
                return 0
            end
            EOD;
        $released = 0;
        foreach ($this->clients as $index => $client) {
            try {
                if ($this->evalScript($client, $script, [$key], [$token])) {
                    ++$released;
                }
            } catch (LockReleaseException $e) {
                // todo throw if there is only one redis server
                $context = [
                    'key' => $key,
                    'index' => $index,
                    'token' => $token,
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
    abstract protected function add(object $client, string $key, string $value, float $expire): bool;

    /**
     * @param TClient      $client
     * @param list<string> $keys
     * @param list<mixed>  $arguments
     *
     * @return mixed The script result, or false if executing failed
     *
     * @throws LockReleaseException An unexpected error happened
     */
    abstract protected function evalScript(object $client, string $luaScript, array $keys, array $arguments);
}
