<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Util\Loop;

/**
 * Serialization is delegated to the database.
 *
 * The critical code is executed within a transaction. The database will decide
 * which parts of that code need to be locked (if at all).
 *
 * A failing transaction will be replayed.
 */
class TransactionalMutex extends AbstractMutex
{
    private \PDO $pdo;

    /** In seconds */
    private float $acquireTimeout;

    /**
     * The PDO object MUST be configured with PDO::ATTR_ERRMODE
     * to throw exceptions on errors.
     *
     * As this implementation spans a transaction over a unit of work,
     * PDO::ATTR_AUTOCOMMIT SHOULD not be enabled.
     *
     * @param float $acquireTimeout In seconds
     */
    public function __construct(\PDO $pdo, float $acquireTimeout = 3)
    {
        if ($pdo->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION) {
            throw new \InvalidArgumentException('The pdo must have PDO::ERRMODE_EXCEPTION set');
        }
        self::checkAutocommit($pdo);

        $this->pdo = $pdo;
        $this->acquireTimeout = $acquireTimeout;
    }

    /**
     * Checks that the AUTOCOMMIT mode is turned off.
     */
    private static function checkAutocommit(\PDO $pdo): void
    {
        $vendor = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        // MySQL turns autocommit off during a transaction.
        if ($vendor === 'mysql') {
            return;
        }

        try {
            if ($pdo->getAttribute(\PDO::ATTR_AUTOCOMMIT)) {
                throw new \InvalidArgumentException('PDO::ATTR_AUTOCOMMIT should be disabled');
            }
        } catch (\PDOException $e) {
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
     */
    #[\Override]
    public function synchronized(callable $code)
    {
        $loop = new Loop();

        return $loop->execute(function () use ($code, $loop) {
            try {
                // BEGIN
                $this->pdo->beginTransaction();
            } catch (\PDOException $e) {
                throw new LockAcquireException('Could not begin transaction', 0, $e);
            }

            try {
                // Unit of work
                $result = $code();
                $this->pdo->commit();
                $loop->end();

                return $result;
            } catch (\Throwable $e) {
                $this->rollBack($e);

                if (self::hasPDOException($e)) {
                    return null; // Replay
                }

                throw $e;
            }
        }, $this->acquireTimeout);
    }

    /**
     * Checks if an exception or any of its previous exceptions is a \PDOException.
     */
    private static function hasPDOException(\Throwable $exception): bool
    {
        if ($exception instanceof \PDOException) {
            return true;
        }

        return $exception->getPrevious() !== null
            && self::hasPDOException($exception->getPrevious());
    }

    /**
     * Rolls back a transaction.
     *
     * @throws LockAcquireException
     */
    private function rollBack(\Throwable $exception): void
    {
        try {
            $this->pdo->rollBack();
        } catch (\PDOException $e) {
            throw new LockAcquireException(
                'Could not roll back transaction: ' . $e->getMessage(),
                0,
                $exception
            );
        }
    }
}
