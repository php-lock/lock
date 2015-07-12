<?php

namespace malkusch\lock;

/**
 * Tests for DoubleCheckedLocking.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see DoubleCheckedLocking
 */
class DoubleCheckedLockingTest extends \PHPUnit_Framework_TestCase
{
    
    /**
     * Tests that the lock will not be acquired for a failing test.
     *
     * @test
     */
    public function testCheckFailsAcquiresNoLock()
    {
        $this->markTestIncomplete();
    }
    
    /**
     * Tests that the check is and execution are in the same lock.
     *
     * @test
     */
    public function testLockedCheckAndExecution()
    {
        $this->markTestIncomplete();
    }
    
    /**
     * Tests that the code is not executed if the first or second check fails.
     *
     * @test
     */
    public function testCodeNotExecuted()
    {
        $this->markTestIncomplete();
    }
    
    /**
     * Tests that the code executed if the checks are true.
     *
     * @test
     */
    public function testCodeExecuted()
    {
        $this->markTestIncomplete();
    }
}
