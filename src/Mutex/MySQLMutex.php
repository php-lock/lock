<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Malkusch\Lock\Util\LockUtil;

class MySQLMutex extends AbstractLockMutex
{
    private \PDO $pdo;

    private string $name;

    /** In seconds */
    private float $acquireTimeout;

    /**
     * @param float $acquireTimeout In seconds
     */
    public function __construct(\PDO $PDO, string $name, float $acquireTimeout = 0)
    {
        $this->pdo = $PDO;

        $namePrefix = LockUtil::getInstance()->getKeyPrefix() . ':';

        if (\strlen($name) > 64 - \strlen($namePrefix)) {
            throw new \InvalidArgumentException('The maximum length of the lock name is ' . (64 - \strlen($namePrefix)) . ' characters');
        }

        $this->name = $namePrefix . $name;
        $this->acquireTimeout = $acquireTimeout;
    }

    #[\Override]
    protected function lock(): void
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');

        // MySQL rounds the value to whole seconds, sadly rounds, not ceils
        // TODO MariaDB supports microseconds precision since 10.1.2 version,
        // but we need to detect the support reliably first
        // https://github.com/MariaDB/server/commit/3e792e6cbccb5d7bf5b84b38336f8a40ad232020
        $acquireTimeoutInt = (int) ceil($this->acquireTimeout);

        $statement->execute([
            $this->name,
            $acquireTimeoutInt,
        ]);

        $statement->setFetchMode(\PDO::FETCH_NUM);
        $row = $statement->fetch();

        if ((string) $row[0] === '1') {
            // Returns 1 if the lock was obtained successfully.
            return;
        }

        if ($row[0] === null) {
            // NULL if an error occurred (such as running out of memory or the thread was killed with mysqladmin kill).
            throw new LockAcquireException('An error occurred while acquiring the lock');
        }

        throw LockAcquireTimeoutException::create($this->acquireTimeout);
    }

    #[\Override]
    protected function unlock(): void
    {
        $statement = $this->pdo->prepare('DO RELEASE_LOCK(?)');
        $statement->execute([
            $this->name,
        ]);
    }
}
