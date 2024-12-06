<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

class PgAdvisoryLockMutex extends LockMutex
{
    /** @var \PDO */
    private $pdo;

    /** @var int */
    private $key1;

    /** @var int */
    private $key2;

    /**
     * @throws \RuntimeException
     */
    public function __construct(\PDO $PDO, string $name)
    {
        $this->pdo = $PDO;

        $hashed_name = hash('sha256', $name, true);

        [$bytes1, $bytes2] = str_split($hashed_name, 4);

        $this->key1 = unpack('i', $bytes1)[1];
        $this->key2 = unpack('i', $bytes2)[1];
    }

    #[\Override]
    protected function lock(): void
    {
        $statement = $this->pdo->prepare('SELECT pg_advisory_lock(?, ?)');

        $statement->execute([
            $this->key1,
            $this->key2,
        ]);
    }

    #[\Override]
    protected function unlock(): void
    {
        $statement = $this->pdo->prepare('SELECT pg_advisory_unlock(?, ?)');
        $statement->execute([
            $this->key1,
            $this->key2,
        ]);
    }
}
