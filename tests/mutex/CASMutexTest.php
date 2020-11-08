<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CASMutex.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 * @see CASMutex
 */
class CASMutexTest extends TestCase
{
    use PHPMock;

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
     * Tests exceeding the execution timeout.
     */
    public function testExceedTimeout()
    {
        $this->expectException(LockAcquireException::class);

        $mutex = new CASMutex(1);
        $mutex->synchronized(function (): void {
            sleep(2);
        });
    }

    /**
     * Tests that an exception would stop any further iteration.
     */
    public function testExceptionStopsIteration()
    {
        $this->expectException(\DomainException::class);

        $mutex = new CASMutex();
        $mutex->synchronized(function () {
            throw new \DomainException();
        });
    }

    /**
     * Tests notify() will stop the iteration and return the result.
     *
     */
    public function testNotify()
    {
        $i = 0;
        $mutex = new CASMutex();
        $mutex->synchronized(function () use ($mutex, &$i) {
            $i++;
            $mutex->notify();
        });
        $this->assertEquals(1, $i);
    }

    /**
     * Tests that the code is executed more times.
     *
     */
    public function testIteration()
    {
        $i = 0;
        $mutex = new CASMutex();
        $mutex->synchronized(function () use ($mutex, &$i): void {
            $i++;
            if ($i > 1) {
                $mutex->notify();
            }
        });
        $this->assertEquals(2, $i);
    }
}
