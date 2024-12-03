<?php

namespace malkusch\lock\util;

use malkusch\lock\exception\TimeoutException;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Loop.
 */
class LoopTest extends TestCase
{
    use PHPMock;

    protected function setUp(): void
    {
        parent::setUp();

        $builder = new SleepEnvironmentBuilder();
        $builder->addNamespace(__NAMESPACE__);
        $sleep = $builder->build();
        $sleep->enable();

        $this->registerForTearDown($sleep);
    }

    /**
     * Test an invalid timeout.
     */
    public function testInvalidTimeout()
    {
        $this->expectException(\LengthException::class);

        new Loop(0);
    }

    /**
     * Tests execution within the timeout.
     */
    public function testExecutionWithinTimeout()
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
    public function testExecutionWithinTimeoutWithoutExplicitEnd()
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
    public function testExceedTimeoutIsAcceptableIfEndWasCalled()
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
    public function testExceedTimeoutWithoutExplicitEnd()
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
    public function testExceptionStopsIteration()
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
    public function testEnd()
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
    public function testIteration()
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
