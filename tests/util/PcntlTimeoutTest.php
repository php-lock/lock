<?php

namespace malkusch\lock\util;

use malkusch\lock\exception\DeadlineException;
use malkusch\lock\exception\LockAcquireException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PcntlTimeout
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see PcntlTimeout
 * @requires pcntl
 */
class PcntlTimeoutTest extends TestCase
{
    /**
     * A long running system call should be interrupted
     */
    public function testShouldTimeout()
    {
        $this->expectException(DeadlineException::class);

        $timeout = new PcntlTimeout(1);

        $timeout->timeBoxed(function () {
            sleep(2);
        });
    }

    /**
     * A short running system call should complete its execution
     */
    public function testShouldNotTimeout()
    {
        $timeout = new PcntlTimeout(1);

        $result = $timeout->timeBoxed(function () {
            return 42;
        });

        $this->assertEquals(42, $result);
    }

    /**
     * When a previous scheduled alarm exists, it should fail
     */
    public function testShouldFailOnExistingAlarm()
    {
        $this->expectException(LockAcquireException::class);

        try {
            pcntl_alarm(1);
            $timeout = new PcntlTimeout(1);

            $timeout->timeBoxed(function () {
                sleep(1);
            });
        } finally {
            pcntl_alarm(0);
        }
    }

    /**
     * After not timing out, there should be no alarm scheduled
     *
     */
    public function testShouldResetAlarmWhenNotTimeout()
    {
        $timeout = new PcntlTimeout(3);

        $timeout->timeBoxed(function () {
        });

        $this->assertEquals(0, pcntl_alarm(0));
    }
}
