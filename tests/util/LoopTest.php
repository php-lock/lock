<?php

namespace malkusch\lock\Tests\util;

use malkusch\lock\exception\TimeoutException;
use malkusch\lock\util\Loop;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\TestCase;

class LoopTest extends TestCase
{
    use PHPMock;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $builder = new SleepEnvironmentBuilder();
        $builder->addNamespace(__NAMESPACE__);
        $builder->addNamespace('malkusch\lock\util');
        $sleep = $builder->build();
        $sleep->enable();

        $this->registerForTearDown($sleep);
    }

    /**
     * Test an invalid timeout.
     */
    public function testInvalidTimeout(): void
    {
        $this->expectException(\LengthException::class);

        new Loop(0);
    }

    /**
     * Tests execution within the timeout.
     */
    public function testExecutionWithinTimeout(): void
    {
        $this->expectNotToPerformAssertions();

        $loop = new Loop(0.5);
        $loop->execute(static function () use ($loop): void {
            usleep(499 * 1000);
            $loop->end();
        });
    }

    /**
     * Tests execution within the timeout without calling end().
     */
    public function testExecutionWithinTimeoutWithoutExplicitEnd(): void
    {
        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Timeout of 0.5 seconds exceeded.');

        $loop = new Loop(0.5);
        $loop->execute(static function (): void {
            usleep(10 * 1000);
        });
    }

    /**
     * Tests exceeding the execution timeout.
     */
    public function testExceedTimeoutIsAcceptableIfEndWasCalled(): void
    {
        $this->expectNotToPerformAssertions();

        $loop = new Loop(0.5);
        $loop->execute(static function () use ($loop): void {
            usleep(501 * 1000);
            $loop->end();
        });
    }

    /**
     * Tests exceeding the execution timeout without calling end().
     */
    public function testExceedTimeoutWithoutExplicitEnd(): void
    {
        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Timeout of 0.5 seconds exceeded.');

        $loop = new Loop(0.5);
        $loop->execute(static function (): void {
            usleep(501 * 1000);
        });
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
        });
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
        });
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
        });
        self::assertSame(2, $i);
    }
}
