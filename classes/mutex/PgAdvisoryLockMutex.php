<?php

namespace malkusch\lock\mutex;

class PgAdvisoryLockMutex extends LockMutex
{
    /**
     * @var \PDO
     */
    private $pdo;

    /**
     * @var int
     */
    private $key1;

    /**
     * @var int
     */
    private $key2;

    /**
     * @throws \RuntimeException
     */
    public function __construct(\PDO $PDO, $name)
    {
        $this->pdo = $PDO;

        $hashed_name = hash("sha256", $name, true);

        if (false === $hashed_name) {
            throw new \RuntimeException("Unable to hash the key, sha256 algorithm is not supported.");
        }

        list($bytes1, $bytes2) = str_split($hashed_name, 4);

        $this->key1 = unpack("i", $bytes1)[1];
        $this->key2 = unpack("i", $bytes2)[1];
    }

    public function lock()
    {
        $statement = $this->pdo->prepare("SELECT pg_advisory_lock(?,?)");

        $statement->execute([
            $this->key1,
            $this->key2,
        ]);
    }

    public function unlock()
    {
        $statement = $this->pdo->prepare("SELECT pg_advisory_unlock(?,?)");
        $statement->execute([
            $this->key1,
            $this->key2
        ]);
    }
}
