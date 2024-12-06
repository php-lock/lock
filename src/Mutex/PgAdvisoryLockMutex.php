<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Util\LockUtil;

class PgAdvisoryLockMutex extends AbstractLockMutex
{
    /** @var \PDO */
    private $pdo;

    /** @var array{int, int} */
    private array $key;

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

        $this->key = [
            $unpackToSignedIntLeFx($keyBytes1),
            $unpackToSignedIntLeFx($keyBytes2),
        ];
    }

    #[\Override]
    protected function lock(): void
    {
        $statement = $this->pdo->prepare('SELECT pg_advisory_lock(?, ?)');

        $statement->execute($this->key);
    }

    #[\Override]
    protected function unlock(): void
    {
        $statement = $this->pdo->prepare('SELECT pg_advisory_unlock(?, ?)');
        $statement->execute($this->key);
    }
}
