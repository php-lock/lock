<?php

declare(strict_types=1);

namespace Malkusch\Lock\Mutex;

use Malkusch\Lock\Util\LockUtil;
use Malkusch\Lock\Util\Loop;

class PostgreSQLMutex extends AbstractLockMutex
{
    private \PDO $pdo;

    /** @var array{int, int} */
    private array $key;

    private ?float $timeout;

    public function __construct(\PDO $PDO, string $name, ?float $timeout = null)
    {
        $this->pdo = $PDO;
        $this->timeout = $timeout;

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
        if (isset($this->timeout)) {
            $this->tryLock();

            return;
        }

        $statement = $this->pdo->prepare('SELECT pg_advisory_lock(?, ?)');
        $statement->execute($this->key);
    }

    #[\Override]
    protected function unlock(): void
    {
        $statement = $this->pdo->prepare('SELECT pg_advisory_unlock(?, ?)');
        $statement->execute($this->key);
    }

    protected function tryLock(): void
    {
        $loop = new Loop();

        $loop->execute(function () use ($loop): void {
            $statement = $this->pdo->prepare('SELECT pg_try_advisory_lock(?, ?)');
            $statement->execute($this->key);

            if ($statement->fetchColumn()) {
                $loop->end();
            }
        }, $this->timeout);
    }
}
