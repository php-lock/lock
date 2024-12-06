<?php

declare(strict_types=1);

namespace malkusch\lock\mutex;

use malkusch\lock\util\LockUtil;

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

        [$keyBytes1, $keyBytes2] = str_split(md5(LockUtil::getInstance()->getKeyPrefix() . ':' . $name, true), 4);

        // https://github.com/php/php-src/issues/17068
        $unpackToSignedIntLeFx = static function (string $v) {
            $unpacked = unpack('va/Cb/cc', $v);

            return $unpacked['a'] | ($unpacked['b'] << 16) | ($unpacked['c'] << 24);
        };

        $this->key1 = $unpackToSignedIntLeFx($keyBytes1);
        $this->key2 = $unpackToSignedIntLeFx($keyBytes2);
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
