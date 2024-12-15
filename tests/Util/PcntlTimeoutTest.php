<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Util;

use Malkusch\Lock\Exception\DeadlineException;
use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Util\PcntlTimeout;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * @requires extension pcntl
 */
#[RequiresPhpExtension('pcntl')]
class PcntlTimeoutTest extends TestCase
{
    /**
     * A long running system call should be interrupted.
     */
    public function testShouldTimeout(): void
    {
        $timeout = new PcntlTimeout(1);

        $this->expectException(DeadlineException::class);
        $timeout->timeBoxed(static function () {
            sleep(2);
        });
    }

    /**
     * A short running system call should complete its execution.
     */
    public function testShouldNotTimeout(): void
    {
        $timeout = new PcntlTimeout(1);

        $result = $timeout->timeBoxed(static function () {
            return 42;
        });

        self::assertSame(42, $result);
    }

    /**
     * Thrown exceptions from the subject code should be rethrown.
     */
    public function testShouldThrowException(): void
    {
        $timeout = new PcntlTimeout(1);

        $this->expectException(\DomainException::class);
        $timeout->timeBoxed(static function () {
            throw new \DomainException();
        });
    }

    /**
     * When a previous scheduled alarm exists, it should fail.
     */
    public function testShouldFailOnExistingAlarm(): void
    {
        $origSignalHandler = pcntl_signal_get_handler(\SIGALRM);
        try {
            pcntl_alarm(1);
            $timeout = new PcntlTimeout(1);

            $this->expectException(LockAcquireException::class);
            $this->expectExceptionMessage('Existing process alarm is not supported');
            $timeout->timeBoxed(static function () {
                sleep(1);
            });
        } finally {
            pcntl_alarm(0);
            self::assertSame($origSignalHandler, pcntl_signal_get_handler(\SIGALRM));
        }
    }

    /**
     * After not timing out, there should be no alarm scheduled.
     */
    public function testShouldResetAlarmWhenNotTimeout(): void
    {
        $timeout = new PcntlTimeout(3);

        $timeout->timeBoxed(static function () {});

        self::assertSame(0, pcntl_alarm(0));
    }

    /**
     * After not timing out and throwing an exception, there should be no alarm scheduled.
     */
    public function testShouldResetAlarmWhenNotTimeoutAndException(): void
    {
        $timeout = new PcntlTimeout(3);

        $this->expectException(\DomainException::class);
        try {
            $timeout->timeBoxed(static function () {
                throw new \DomainException();
            });
        } finally {
            self::assertSame(0, pcntl_alarm(0));
        }
    }
}
