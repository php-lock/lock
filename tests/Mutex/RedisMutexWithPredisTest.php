<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Mutex\RedisMutex;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface as PredisClientInterface;
use Predis\PredisException;

interface PredisClientInterfaceWithSetAndEvalMethods2 extends PredisClientInterface
{
    /**
     * @return mixed
     */
    public function eval();

    /**
     * @return mixed
     */
    public function set();
}

class RedisMutexWithPredisTest extends TestCase
{
    /** @var PredisClientInterface&MockObject */
    private $client;

    /** @var RedisMutex */
    private $mutex;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(PredisClientInterfaceWithSetAndEvalMethods2::class);

        $this->mutex = new RedisMutex($this->client, 'test', 2.5, 3.5);
    }

    /**
     * Tests add() fails.
     */
    public function testAddFailsToSetKey(): void
    {
        $this->client->expects(self::atLeastOnce())
            ->method('set')
            ->with('php-malkusch-lock:test', new IsType(IsType::TYPE_STRING), 'PX', 3501, 'NX')
            ->willReturn(null);

        $this->expectException(LockAcquireException::class);

        $this->mutex->synchronized(static function () {
            self::fail();
        });
    }

    /**
     * Tests add() errors.
     */
    public function testAddErrors(): void
    {
        $this->client->expects(self::atLeastOnce())
            ->method('set')
            ->with('php-malkusch-lock:test', new IsType(IsType::TYPE_STRING), 'PX', 3501, 'NX')
            ->willThrowException($this->createMock(PredisException::class));

        $this->expectException(LockAcquireException::class);

        $this->mutex->synchronized(static function () {
            self::fail();
        });
    }

    public function testWorksNormally(): void
    {
        $this->client->expects(self::atLeastOnce())
            ->method('set')
            ->with('php-malkusch-lock:test', new IsType(IsType::TYPE_STRING), 'PX', 3501, 'NX')
            ->willReturnSelf();

        $this->client->expects(self::once())
            ->method('eval')
            ->with(self::anything(), 1, 'php-malkusch-lock:test', new IsType(IsType::TYPE_STRING))
            ->willReturn(true);

        $executed = false;
        $this->mutex->synchronized(static function () use (&$executed) {
            $executed = true;
        });

        self::assertTrue($executed);
    }

    /**
     * Tests evalScript() fails.
     */
    public function testEvalScriptFails(): void
    {
        $this->client->expects(self::atLeastOnce())
            ->method('set')
            ->with('php-malkusch-lock:test', new IsType(IsType::TYPE_STRING), 'PX', 3501, 'NX')
            ->willReturnSelf();

        $this->client->expects(self::once())
            ->method('eval')
            ->with(self::anything(), 1, 'php-malkusch-lock:test', new IsType(IsType::TYPE_STRING))
            ->willThrowException($this->createMock(PredisException::class));

        $this->expectException(LockReleaseException::class);

        $executed = false;
        $this->mutex->synchronized(static function () use (&$executed) {
            $executed = true;
        });

        self::assertTrue($executed);
    }
}
