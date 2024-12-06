<?php

declare(strict_types=1);

namespace malkusch\lock\Tests\mutex;

use malkusch\lock\mutex\MySQLMutex;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MySQLMutexTest extends TestCase
{
    /** @var \PDO&MockObject */
    private $pdo;

    /** @var MySQLMutex */
    private $mutex;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = $this->createMock(\PDO::class);

        $this->mutex = new MySQLMutex($this->pdo, 'test');
    }

    public function testAcquireLock(): void
    {
        $statement = $this->createMock(\PDOStatement::class);

        $this->pdo->expects(self::once())
            ->method('prepare')
            ->with('SELECT GET_LOCK(?, ?)')
            ->willReturn($statement);

        $statement->expects(self::once())
            ->method('execute')
            ->with(['php-malkusch-lock:test', 0]);

        $statement->expects(self::once())
            ->method('fetch')
            ->willReturn([1]);

        \Closure::bind(static fn ($mutex) => $mutex->lock(), null, MySQLMutex::class)($this->mutex);
    }

    public function testReleaseLock(): void
    {
        $statement = $this->createMock(\PDOStatement::class);

        $this->pdo->expects(self::once())
            ->method('prepare')
            ->with('DO RELEASE_LOCK(?)')
            ->willReturn($statement);

        $statement->expects(self::once())
            ->method('execute')
            ->with(['php-malkusch-lock:test']);

        \Closure::bind(static fn ($mutex) => $mutex->unlock(), null, MySQLMutex::class)($this->mutex);
    }
}
