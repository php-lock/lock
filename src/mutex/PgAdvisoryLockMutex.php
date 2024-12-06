<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

class PgAdvisoryLockMutex extends LockMutex
{
    /** @var \PDO */
    private $pdo;

    private int $key1;

    private int $key2;

    /**
     * @throws \RuntimeException
     */
    public function __construct(\PDO $PDO, string $name)
    {
        $this->pdo = $PDO;

        [$keyBytes1, $keyBytes2] = str_split(md5($name, true), 4);

        $unpackToSignedIntFx = static function (string $v) {
            $unpacked = unpack('va/Cb/cc', $v);

            return ($unpacked['c'] << 24) | ($unpacked['b'] << 16) | $unpacked['a'];
        };

        $this->key1 = $unpackToSignedIntFx($keyBytes1);
        $this->key2 = $unpackToSignedIntFx($keyBytes2);
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
