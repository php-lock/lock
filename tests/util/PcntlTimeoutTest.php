<?php

namespace malkusch\lock\util;

use malkusch\lock\exception\TimeoutException;

/**
 * Tests for PcntlTimeout
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see PcntlTimeout
 * @requires pcntl
 */
class PcntlTimeoutTest extends \PHPUnit_Framework_TestCase
{

    /**
     * A long running system call should be interrupted
     *
     * @test
     * @expectedException malkusch\lock\exception\TimeoutException
     */
    public function shouldTimeout()
    {
        $timeout = new PcntlTimeout(1);

        $timeout->timeBoxed(function () {
            sleep(2);
        });
    }

    /**
     * A long running system call should be interrupted,
     * any thrown exception should be ignored.
     *
     * @test
     * @expectedException malkusch\lock\exception\TimeoutException
     */
    public function shouldTimeoutAndIgnoreException()
    {
        $timeout = new PcntlTimeout(1);

        $timeout->timeBoxed(function () {
            sleep(2);
            throw new \DomainException("Swallow me");
        });
    }

    /**
     * A short running system call should complete its execution
     *
     * @test
     */
    public function shouldNotTimeout()
    {
        $timeout = new PcntlTimeout(1);

        $result = $timeout->timeBoxed(function () {
            return 42;
        });

        $this->assertEquals(42, $result);
    }

    /**
     * Thrown exceptions from the subject code should be rethrown
     *
     * @test
     * @expectedException \DomainException
     */
    public function shouldThrowException()
    {
        $timeout = new PcntlTimeout(1);

        $timeout->timeBoxed(function () {
            throw new \DomainException();
        });
    }

    /**
     * When a previous scheduled alarm exists, it should fail
     *
     * @test
     * @expectedException malkusch\lock\exception\LockAcquireException
     */
    public function shouldFailOnExistingAlarm()
    {
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
     * @test
     */
    public function shouldResetAlarmWhenNotTimeout()
    {
        $timeout = new PcntlTimeout(3);

        $timeout->timeBoxed(function () {
        });

        $this->assertEquals(0, pcntl_alarm(0));
    }

    /**
     * After not timing out and throwing an exception, there should be no alarm scheduled
     *
     * @test
     */
    public function shouldResetAlarmWhenNotTimeoutAndException()
    {
        $timeout = new PcntlTimeout(3);

        try {
            $timeout->timeBoxed(function () {
                throw new \DomainException();
            });
        } catch (\DomainException $e) {
            // expected
        }

        $this->assertEquals(0, pcntl_alarm(0));
    }

    /**
     * After a timeout, the default signal handler should be installed
     *
     * @test
     */
    public function shouldResetToDefaultHandlerAfterTimeout()
    {
        $pid = pcntl_fork();
        if ($pid == -1) {
            $this->fail('could not fork');
        } elseif ($pid === 0) {
            try {
                $timeout = new PcntlTimeout(1);
                $timeout->timeBoxed(function () {
                    sleep(3);
                });
            } catch (TimeoutException $e) {
                // expected
            }

            pcntl_alarm(1);
            sleep(3);
            exit;
        }
        pcntl_wait($status);

        $this->assertEquals(SIGALRM, pcntl_wtermsig($status));
    }

    /**
     * After no timeout, the default signal handler should be installed
     *
     * @test
     */
    public function shouldResetToDefaultHandlerAfterNoTimeout()
    {
        $pid = pcntl_fork();
        if ($pid == -1) {
            $this->fail('could not fork');
        } elseif ($pid === 0) {
            $timeout = new PcntlTimeout(3);
            $timeout->timeBoxed(function () {
                return 42;
            });

            pcntl_alarm(1);
            sleep(3);
            exit;
        }
        pcntl_wait($status);

        $this->assertEquals(SIGALRM, pcntl_wtermsig($status));
    }

    /**
     * After no timeout and throwing an exception, the default signal handler should be installed
     *
     * @test
     */
    public function shouldResetToDefaultHandlerAfterNoTimeoutAndException()
    {
        $pid = pcntl_fork();
        if ($pid == -1) {
            $this->fail('could not fork');
        } elseif ($pid === 0) {
            try {
                $timeout = new PcntlTimeout(3);
                $timeout->timeBoxed(function () {
                    throw new \DomainException();
                });
            } catch (\DomainException $e) {
                // expected
            }

            pcntl_alarm(1);
            sleep(3);
            exit;
        }
        pcntl_wait($status);

        $this->assertEquals(SIGALRM, pcntl_wtermsig($status));
    }
}
