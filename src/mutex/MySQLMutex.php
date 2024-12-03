<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

use InvalidArgumentException;
use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\TimeoutException;

class MySQLMutex extends LockMutex
{
    /**
     * @var \PDO
     */
    private $pdo;

    /**
     * @var string
     */
    private $name;
    /**
     * @var float
     */
    private $timeout;

    public function __construct(\PDO $PDO, string $name, float $timeout = 0)
    {
        $this->pdo = $PDO;

        if (\strlen($name) > 64) {
            throw new InvalidArgumentException('The maximum length of the lock name is 64 characters.');
        }

        $this->name = $name;
        $this->timeout = $timeout;
    }

    /**
     * @throws LockAcquireException
     */
    public function lock(): void
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?,?)');

        // MySQL rounds the value to whole seconds, sadly rounds, not ceils
        // TODO MariaDB supports microseconds precision since 10.1.2 version,
        // but we need to detect the support reliably first
        // https://github.com/MariaDB/server/commit/3e792e6cbccb5d7bf5b84b38336f8a40ad232020
        $timeoutInt = (int) ceil($this->timeout);

        $statement->execute([
            $this->name,
            $timeoutInt,
        ]);

        $statement->setFetchMode(\PDO::FETCH_NUM);
        $row = $statement->fetch();

        if ($row[0] == 1) {
            // Returns 1 if the lock was obtained successfully.
            return;
        }

        if ($row[0] === null) {
            // NULL if an error occurred (such as running out of memory or the thread was killed with mysqladmin kill).
            throw new LockAcquireException('An error occurred while acquiring the lock');
        }

        throw TimeoutException::create($this->timeout);
    }

    public function unlock(): void
    {
        $statement = $this->pdo->prepare('DO RELEASE_LOCK(?)');
        $statement->execute([
            $this->name,
        ]);
    }
}
