<?php

namespace malkusch\lock\mutex;

/**
 * Tests for MemcacheMutex.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see MemcacheMutex
 */
class MemcacheMutexTest extends \PHPUnit_Framework_TestCase
{

    /**
     * Tests hitting the timeout.
     *
     * @test
     */
    public function testTimeout()
    {
        $this->markTestIncomplete();
    }

    /**
     * Tests executing code which exceeds the timeout fails.
     *
     * @test
     */
    public function testExecuteTooLong()
    {
        $this->markTestIncomplete();
    }

    /**
     * Tests executing exactly unil the timeout will leave the key one more second.
     *
     * @test
     */
    public function testExecuteTimeoutLeavesOneSecondForKeyToExpire()
    {
        $this->markTestIncomplete();
    }
    
    
    /**
     * Tests failing to release a lock.
     *
     * @test
     */
    public function testFailReleasingLock()
    {
        $this->markTestIncomplete();
    }
}
