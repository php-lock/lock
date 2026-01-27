<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

require_once __DIR__ . '/../TestAccess.php';
use Malkusch\Lock\Exception\DeadlineException;
use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Malkusch\Lock\Mutex\FlockMutex;
use Malkusch\Lock\Tests\TestAccess;
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

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->file = LockUtil::getInstance()->makeRandomTemporaryFilePath('flock');
        touch($this->file);
        $this->mutex = new FlockMutex(fopen($this->file, 'r'), 1);
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
        (new TestAccess($this->mutex))->setProperty('strategy', $strategy);

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

        $this->mutex->strategy = $strategy; // @phpstan-ignore property.private

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
     */
    public static function provideTimeoutableStrategiesCases(): iterable
    {
        if (extension_loaded('pcntl')) {
            yield [\Closure::bind(static fn () => FlockMutex::STRATEGY_PCNTL, null, FlockMutex::class)()];
        }

        yield [\Closure::bind(static fn () => FlockMutex::STRATEGY_LOOP, null, FlockMutex::class)()];
    }

    /**
     * @requires extension pcntl
     */
    #[RequiresPhpExtension('pcntl')]
    public function testNoTimeoutWaitsForever(): void
    {
        $anotherResource = fopen($this->file, 'r');
        flock($anotherResource, \LOCK_EX);

        $this->mutex->strategy = \Closure::bind(static fn () => FlockMutex::STRATEGY_BLOCK, null, FlockMutex::class)(); // @phpstan-ignore property.private

        $timebox = new PcntlTimeout(1);

        $this->expectException(DeadlineException::class);
        $timebox->timeBoxed(function () {
            $this->mutex->synchronized(static function () {
                self::fail();
            });
        });
    }
}
