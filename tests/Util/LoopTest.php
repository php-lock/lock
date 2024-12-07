<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Util;

use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Malkusch\Lock\Util\LockUtil;
use Malkusch\Lock\Util\Loop;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\MockEnabledException;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

class LoopTest extends TestCase
{
    use PHPMock;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $sleepBuilder = new SleepEnvironmentBuilder();
        $sleepBuilder->addNamespace(__NAMESPACE__);
        $sleepBuilder->addNamespace('Malkusch\Lock\Util');
        $sleep = $sleepBuilder->build();
        try {
            $sleep->enable();
            $this->registerForTearDown($sleep);
        } catch (MockEnabledException $e) {
            // workaround for burn testing
            \assert($e->getMessage() === 'microtime is already enabled. Call disable() on the existing mock.');
        }
    }

    /**
     * @dataProvider provideInvalidAcquireTimeoutCases
     */
    #[DataProvider('provideInvalidAcquireTimeoutCases')]
    public function testInvalidAcquireTimeout(float $acquireTimeout): void
    {
        $loop = new Loop();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The lock acquire timeout must be greater than or equal to 0.0 (' . LockUtil::getInstance()->formatTimeout($acquireTimeout) . ' was given)');

        $loop->execute(static function (): void {
            self::fail();
        }, $acquireTimeout);
    }

    /**
     * @return iterable<list<mixed>>
     */
    public static function provideInvalidAcquireTimeoutCases(): iterable
    {
        yield [-2];
        yield [-0.1];
        yield [-\INF];
        yield [\NAN];
    }

    /**
     * Tests execution within the timeout.
     *
     * @doesNotPerformAssertions
     */
    #[DoesNotPerformAssertions]
    public function testExecutionWithinTimeout(): void
    {
        $loop = new Loop();
        $loop->execute(static function () use ($loop): void {
            usleep(499 * 1000);
            $loop->end();
        }, 0.5);
    }

    /**
     * Tests execution within the timeout without calling end().
     */
    public function testExecutionWithinAcquireTimeoutWithoutExplicitEnd(): void
    {
        $this->expectException(LockAcquireTimeoutException::class);
        $this->expectExceptionMessage('Lock acquire timeout of 0.5 seconds has been exceeded');

        $loop = new Loop();
        $loop->execute(static function (): void {
            usleep(10 * 1000);
        }, 0.5);
    }

    /**
     * Tests exceeding the execution timeout.
     *
     * @doesNotPerformAssertions
     */
    #[DoesNotPerformAssertions]
    public function testExceedTimeoutIsAcceptableIfEndWasCalled(): void
    {
        $loop = new Loop();
        $loop->execute(static function () use ($loop): void {
            usleep(501 * 1000);
            $loop->end();
        }, 0.5);
    }

    /**
     * Tests exceeding the execution timeout without calling end().
     */
    public function testExceedAcquireTimeoutWithoutExplicitEnd(): void
    {
        $this->expectException(LockAcquireTimeoutException::class);
        $this->expectExceptionMessage('Lock acquire timeout of 0.5 seconds has been exceeded');

        $loop = new Loop();
        $loop->execute(static function (): void {
            usleep(501 * 1000);
        }, 0.5);
    }

    /**
     * Tests that an exception would stop any further iteration.
     */
    public function testExceptionStopsIteration(): void
    {
        $this->expectException(\DomainException::class);

        $loop = new Loop();
        $loop->execute(static function () {
            throw new \DomainException();
        }, 1);
    }

    /**
     * Tests end() will stop the iteration and return the result.
     */
    public function testEnd(): void
    {
        $i = 0;
        $loop = new Loop();
        $loop->execute(static function () use ($loop, &$i) {
            ++$i;
            $loop->end();
        }, 1);
        self::assertSame(1, $i);
    }

    /**
     * Tests that the code is executed more times.
     */
    public function testIteration(): void
    {
        $i = 0;
        $loop = new Loop();
        $loop->execute(static function () use ($loop, &$i): void {
            ++$i;
            if ($i > 1) {
                $loop->end();
            }
        }, 1);
        self::assertSame(2, $i);
    }
}
