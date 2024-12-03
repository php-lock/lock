<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Predis\PredisException;
use Psr\Log\LoggerInterface;

/**
 * Tests for PredisMutex.
 *
 * @group redis
 */
class PredisMutexTest extends TestCase
{
    /** @var ClientInterface|MockObject */
    private $client;

    /** @var PredisMutex */
    private $mutex;

    /** @var LoggerInterface|MockObject */
    private $logger;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->getMockBuilder(ClientInterface::class) // @phpstan-ignore method.deprecated
            ->setMethods(array_merge(get_class_methods(ClientInterface::class), ['set', 'eval']))
            ->getMock();

        $this->mutex = new PredisMutex([$this->client], 'test', 2.5);

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
            ->with('lock_test', self::isType('string'), 'PX', 3500, 'NX')
            ->willReturn(null);

        $this->logger->expects(self::never())
            ->method('warning');

        $this->expectException(LockAcquireException::class);

        $this->mutex->synchronized(
            static function (): void {
                self::fail('Code execution is not expected');
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
            ->with('lock_test', self::isType('string'), 'PX', 3500, 'NX')
            ->willThrowException($this->createMock(PredisException::class));

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('Could not set {key} = {token} at server #{index}.', self::anything());

        $this->expectException(LockAcquireException::class);

        $this->mutex->synchronized(
            static function () {
                self::fail('Code execution is not expected');
            }
        );
    }

    public function testWorksNormally(): void
    {
        $this->client->expects(self::atLeastOnce())
            ->method('set')
            ->with('lock_test', self::isType('string'), 'PX', 3500, 'NX')
            ->willReturnSelf();

        $this->client->expects(self::once())
            ->method('eval')
            ->with(self::anything(), 1, 'lock_test', self::isType('string'))
            ->willReturn(true);

        $executed = false;

        $this->mutex->synchronized(static function () use (&$executed): void {
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
            ->with('lock_test', self::isType('string'), 'PX', 3500, 'NX')
            ->willReturnSelf();

        $this->client->expects(self::once())
            ->method('eval')
            ->with(self::anything(), 1, 'lock_test', self::isType('string'))
            ->willThrowException($this->createMock(PredisException::class));

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('Could not unset {key} = {token} at server #{index}.', self::anything());

        $executed = false;

        $this->expectException(LockReleaseException::class);

        $this->mutex->synchronized(static function () use (&$executed): void {
            $executed = true;
        });

        self::assertTrue($executed);
    }
}
