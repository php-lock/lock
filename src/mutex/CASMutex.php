<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

use malkusch\lock\exception\TimeoutException;
use malkusch\lock\util\Loop;

/**
 * CAS based mutex implementation.
 *
 * This mutex doesn't lock at all. It implements the compare-and-swap
 * approach. I.e. it will repeat executing the code block until it wasn't
 * modified in between. Use this only when you know that concurrency is
 * a rare event.
 */
class CASMutex extends Mutex
{
    /** @var Loop the loop */
    private $loop;

    /**
     * Sets the timeout.
     *
     * The default is 3 seconds.
     *
     * @param float $timeout the timeout in seconds
     *
     * @throws \LengthException the timeout must be greater than 0
     */
    public function __construct(float $timeout = 3)
    {
        $this->loop = new Loop($timeout);
    }

    /**
     * Notifies the Mutex about a successful CAS operation.
     */
    public function notify(): void
    {
        $this->loop->end();
    }

    /**
     * Repeats executing a code until a compare-and-swap operation was successful.
     *
     * The code has to be designed in a way that it can be repeated without any
     * side effects. When the CAS operation was successful it should notify
     * this mutex by calling {@link CASMutex::notify()}. I.e. the only side effects
     * of the code may happen after a successful CAS operation. The CAS
     * operation itself is a valid side effect as well.
     *
     * If the code throws an exception it will stop repeating the execution.
     *
     * Example:
     * <code>
     * $mutex = new CASMutex();
     * $mutex->synchronized(static function () use ($memcached, $mutex, $amount) {
     *     $balance = $memcached->get('balance', null, $casToken);
     *     $balance -= $amount;
     *     if (!$memcached->cas($casToken, 'balance', $balance)) {
     *         return;
     *     }
     *     $mutex->notify();
     * });
     * </code>
     *
     * @throws \Exception       the execution block threw an exception
     * @throws TimeoutException the timeout was reached
     */
    #[\Override]
    public function synchronized(callable $code)
    {
        return $this->loop->execute($code);
    }
}
