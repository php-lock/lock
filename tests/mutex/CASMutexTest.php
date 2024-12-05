<?php

declare(strict_types=1);

namespace malkusch\lock\Tests\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\mutex\CASMutex;
use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\MockEnabledException;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\TestCase;

class CASMutexTest extends TestCase
{
    use PHPMock;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $sleepBuilder = new SleepEnvironmentBuilder();
        $sleepBuilder->addNamespace(__NAMESPACE__);
        $sleepBuilder->addNamespace('malkusch\lock\mutex');
        $sleepBuilder->addNamespace('malkusch\lock\util');
        $sleep = $sleepBuilder->build();
        try {
            $sleep->enable();
            $this->registerForTearDown($sleep);
        } catch (MockEnabledException $e) {
            // workaround for burn testing
            \assert($e->getMessage() === 'microtime is already enabled.Call disable() on the existing mock.');
        }
    }

    /**
     * Tests exceeding the execution timeout.
     */
    public function testExceedTimeout(): void
    {
        $this->expectException(LockAcquireException::class);

        $mutex = new CASMutex(1);
        $mutex->synchronized(static function (): void {
            sleep(2);
        });
    }

    /**
     * Tests that an exception would stop any further iteration.
     */
    public function testExceptionStopsIteration(): void
    {
        $this->expectException(\DomainException::class);

        $mutex = new CASMutex();
        $mutex->synchronized(static function () {
            throw new \DomainException();
        });
    }

    /**
     * Tests notify() will stop the iteration and return the result.
     */
    public function testNotify(): void
    {
        $i = 0;
        $mutex = new CASMutex();
        $mutex->synchronized(static function () use ($mutex, &$i) {
            ++$i;
            $mutex->notify();
        });
        self::assertSame(1, $i);
    }

    /**
     * Tests that the code is executed more times.
     */
    public function testIteration(): void
    {
        $i = 0;
        $mutex = new CASMutex();
        $mutex->synchronized(static function () use ($mutex, &$i): void {
            ++$i;
            if ($i > 1) {
                $mutex->notify();
            }
        });
        self::assertSame(2, $i);
    }
}
