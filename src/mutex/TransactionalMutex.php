<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

use Exception;
use InvalidArgumentException;
use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\util\Loop;
use PDO;
use PDOException;

/**
 * Serialization is delegated to the DBS.
 *
 * The critical code is executed within a transaction. The DBS will decide
 * which parts of that code need to be locked (if at all).
 *
 * A failing transaction will be replayed.
 */
class TransactionalMutex extends Mutex
{
    /**
     * @var \PDO the PDO
     */
    private $pdo;

    /**
     * @var Loop the loop
     */
    private $loop;

    /**
     * Sets the PDO.
     *
     * The PDO object MUST be configured with PDO::ATTR_ERRMODE
     * to throw exceptions on errors.
     *
     * As this implementation spans a transaction over a unit of work,
     * PDO::ATTR_AUTOCOMMIT SHOULD not be enabled.
     *
     * @param \PDO  $pdo     the PDO
     * @param float $timeout the timeout in seconds, default is 3
     *
     * @throws \LengthException the timeout must be greater than 0
     */
    public function __construct(\PDO $pdo, float $timeout = 3)
    {
        if ($pdo->getAttribute(\PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('The pdo must have PDO::ERRMODE_EXCEPTION set.');
        }
        self::checkAutocommit($pdo);

        $this->pdo = $pdo;
        $this->loop = new Loop($timeout);
    }

    /**
     * Checks that the AUTOCOMMIT mode is turned off.
     *
     * @param \PDO $pdo PDO
     */
    private static function checkAutocommit(\PDO $pdo): void
    {
        $vendor = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        // MySQL turns autocommit off during a transaction.
        if ($vendor == 'mysql') {
            return;
        }

        try {
            if ($pdo->getAttribute(\PDO::ATTR_AUTOCOMMIT)) {
                throw new InvalidArgumentException('PDO::ATTR_AUTOCOMMIT should be disabled.');
            }
        } catch (PDOException $e) {
            /*
             * Ignore this, as some drivers would throw an exception for an
             * unsupported attribute (e.g. Postgres).
             */
        }
    }

    /**
     * Executes the critical code within a transaction.
     *
     * It's up to the user to set the correct transaction isolation level.
     * However if the transaction fails, the code will be executed again in a
     * new transaction. Therefore the code must not have any side effects
     * besides SQL statements. Also the isolation level should be conserved for
     * the repeated transaction.
     *
     * A transaction is considered as failed if a PDOException or an exception
     * which has a PDOException as any previous exception was raised.
     *
     * If the code throws any other exception, the transaction is rolled back
     * and won't  be replayed.
     *
     * @template T
     *
     * @param callable(): T $code the synchronized execution block
     *
     * @return T the return value of the execution block
     *
     * @throws \Exception           the execution block threw an exception
     * @throws LockAcquireException the transaction was not commited
     */
    public function synchronized(callable $code)
    {
        return $this->loop->execute(function () use ($code) {
            try {
                // BEGIN
                $this->pdo->beginTransaction();
            } catch (PDOException $e) {
                throw new LockAcquireException('Could not begin transaction.', 0, $e);
            }

            try {
                // Unit of work
                $result = $code();
                $this->pdo->commit();
                $this->loop->end();

                return $result;
            } catch (Exception $e) {
                $this->rollBack($e);

                if (self::hasPDOException($e)) {
                    return null; // Replay
                } else {
                    throw $e;
                }
            }
        });
    }

    /**
     * Checks if an exception or any of its previous exceptions is a PDOException.
     *
     * @param \Throwable $exception the exception
     *
     * @return bool true if there's a PDOException
     */
    private static function hasPDOException(\Throwable $exception)
    {
        if ($exception instanceof PDOException) {
            return true;
        }
        if ($exception->getPrevious() === null) {
            return false;
        }

        return self::hasPDOException($exception->getPrevious());
    }

    /**
     * Rolls back a transaction.
     *
     * @param \Exception $exception the causing exception
     *
     * @throws LockAcquireException the roll back failed
     */
    private function rollBack(\Exception $exception)
    {
        try {
            $this->pdo->rollBack();
        } catch (\PDOException $e2) {
            throw new LockAcquireException(
                "Could not roll back transaction: {$e2->getMessage()})",
                0,
                $exception
            );
        }
    }
}
