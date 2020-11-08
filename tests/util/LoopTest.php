<?php

namespace malkusch\lock\util;

use malkusch\lock\exception\TimeoutException;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Loop.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 * @see Loop
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

        $loop = new Loop(1);
        $loop->execute(function () use ($loop): void {
            usleep(999999);
            $loop->end();
        });
    }

    /**
     * Tests exceeding the execution timeout.
     */
    public function testExceedTimeout()
    {
        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Timeout of 1 seconds exceeded.');

        $loop = new Loop(1);
        $loop->execute(function () use ($loop): void {
            sleep(1);
            $loop->end();
        });
    }

    /**
     * Tests exceeding the execution timeout without calling end().
     */
    public function testExceedTimeoutWithoutExplicitEnd()
    {
        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Timeout of 1 seconds exceeded.');

        $loop = new Loop(1);
        $loop->execute(function (): void {
            sleep(1);
        });
    }

    /**
     * Tests that an exception would stop any further iteration.
     */
    public function testExceptionStopsIteration()
    {
        $this->expectException(\DomainException::class);

        $loop = new Loop();
        $loop->execute(function () {
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
        $loop->execute(function () use ($loop, &$i) {
            $i++;
            $loop->end();
        });
        $this->assertEquals(1, $i);
    }

    /**
     * Tests that the code is executed more times.
     */
    public function testIteration()
    {
        $i = 0;
        $loop = new Loop();
        $loop->execute(function () use ($loop, &$i): void {
            $i++;
            if ($i > 1) {
                $loop->end();
            }
        });
        $this->assertSame(2, $i);
    }
}
