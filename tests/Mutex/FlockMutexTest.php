<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

use Malkusch\Lock\Exception\DeadlineException;
use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Malkusch\Lock\Mutex\FlockMutex;
use Malkusch\Lock\Util\LockUtil;
use Malkusch\Lock\Util\PcntlTimeout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

class FlockMutexTest extends TestCase
{
    /** @var FlockMutex */
    private $mutex;

    private string $file;

    /**
     * @throws \ReflectionException
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->file = LockUtil::getInstance()->makeRandomTemporaryFilePath('flock');
        touch($this->file);
        $this->mutex = $this->withStrategy(
            new FlockMutex(fopen($this->file, 'r'), 1),
            self::getPrivateConstant(FlockMutex::class, 'STRATEGY_LOOP')
        );
    }

    /**
     * @throws \ReflectionException
     */
    private static function getPrivateConstant(string $class, string $name): string
    {
        return (new \ReflectionClass($class))->getConstant($name);
    }

    /**
     * Helper to set a non-public FlockMutex strategy without Liberator.
     */
    private function withStrategy(FlockMutex $mutex, string $strategy): FlockMutex
    {
        $reflection = new \ReflectionClass($mutex);
        $property = $reflection->getProperty('strategy');

        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        $property->setValue($mutex, $strategy);

        return $mutex;
    }

    #[\Override]
    protected function tearDown(): void
    {
        unlink($this->file);

        parent::tearDown();
    }

    /**
     * @param FlockMutex::STRATEGY_* $strategy
     *
     * @dataProvider provideTimeoutableStrategiesCases
     */
    #[DataProvider('provideTimeoutableStrategiesCases')]
    public function testCodeExecutedOutsideLockIsNotThrown(string $strategy): void
    {
        $this->withStrategy($this->mutex, $strategy);
        self::assertTrue($this->mutex->synchronized(static function () { // @phpstan-ignore staticMethod.alreadyNarrowedType
            usleep(1100 * 1000);

            return true;
        }));
    }

    /**
     * @param FlockMutex::STRATEGY_* $strategy
     *
     * @dataProvider provideTimeoutableStrategiesCases
     */
    #[DataProvider('provideTimeoutableStrategiesCases')]
    public function testAcquireTimeoutOccurs(string $strategy): void
    {
        $anotherResource = fopen($this->file, 'r');
        flock($anotherResource, \LOCK_EX);

        $this->withStrategy($this->mutex, $strategy);

        $this->expectException(LockAcquireTimeoutException::class);
        $this->expectExceptionMessage('Lock acquire timeout of 1.0 seconds has been exceeded');
        try {
            $this->mutex->synchronized(static function () {
                self::fail();
            });
        } finally {
            fclose($anotherResource);
        }
    }

    /**
     * @return iterable<list<mixed>>
     *
     * @throws \ReflectionException
     */
    public static function provideTimeoutableStrategiesCases(): iterable
    {
        if (extension_loaded('pcntl')) {
            yield [self::getPrivateConstant(FlockMutex::class, 'STRATEGY_PCNTL')];
        }

        yield [self::getPrivateConstant(FlockMutex::class, 'STRATEGY_LOOP')];
    }

    /**
     * @requires extension pcntl
     */
    #[RequiresPhpExtension('pcntl')]
    public function testNoTimeoutWaitsForever(): void
    {
        $anotherResource = fopen($this->file, 'r');
        flock($anotherResource, \LOCK_EX);

        $this->withStrategy(
            $this->mutex,
            self::getPrivateConstant(FlockMutex::class, 'STRATEGY_BLOCK')
        );

        $timebox = new PcntlTimeout(1);

        $this->expectException(DeadlineException::class);
        $timebox->timeBoxed(function () {
            $this->mutex->synchronized(static function () {
                self::fail();
            });
        });
    }
}
