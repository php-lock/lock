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
use Psr\Log\LoggerInterface;

interface PredisClientInterfaceWithSetAndEvalMethods extends PredisClientInterface
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

    /** @var LoggerInterface&MockObject */
    private $logger;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(PredisClientInterfaceWithSetAndEvalMethods::class);

        $this->mutex = new RedisMutex([$this->client], 'test', 2.5, 3.5);

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->mutex->setLogger($this->logger);
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

        $this->logger->expects(self::never())
            ->method('warning');

        $this->expectException(LockAcquireException::class);

        $this->mutex->synchronized(
            static function (): void {
                self::fail();
            }
        );
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

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('Could not set {key} = {token} at server #{index}', self::anything());

        $this->expectException(LockAcquireException::class);

        $this->mutex->synchronized(
            static function () {
                self::fail();
            }
        );
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

        $this->mutex->synchronized(static function () use (&$executed): void {
            $executed = true;
        });

        self::assertTrue($executed);
    }

    public function testAcquireExpireTimeoutLimit(): void
    {
        $this->mutex = new RedisMutex([$this->client], 'test');

        $this->client->expects(self::once())
            ->method('set')
            ->with('php-malkusch-lock:test', new IsType(IsType::TYPE_STRING), 'PX', 31_557_600_000_000, 'NX')
            ->willReturnSelf();

        $this->client->expects(self::once())
            ->method('eval')
            ->with(self::anything(), 1, 'php-malkusch-lock:test', new IsType(IsType::TYPE_STRING))
            ->willReturn(true);

        $this->mutex->synchronized(static function (): void {});
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

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('Could not unset {key} = {token} at server #{index}', self::anything());

        $executed = false;

        $this->expectException(LockReleaseException::class);

        $this->mutex->synchronized(static function () use (&$executed): void {
            $executed = true;
        });

        self::assertTrue($executed);
    }
}
