<?php

namespace malkusch\lock\mutex;

/**
 * Tests for LockMutex.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see LockMutex
 */
class LockMutexTest extends \PHPUnit_Framework_TestCase
{
    
    /**
     * Tests lock() fails and the code is not executed.
     *
     * @test
     * @expectedException malkusch\lock\exception\LockAcquireException
     */
    public function testLockFails()
    {
        $this->markTestIncomplete();
    }
    
    /**
     * Tests lock() fails and the code is not executed.
     *
     * @test
     * @expectedException malkusch\lock\exception\LockAcquireException
     */
    public function testLockReturnsFalse()
    {
        $this->markTestIncomplete();
    }
    
    /**
     * Tests unlock() is called after the code was executed.
     *
     * @test
     */
    public function testUnlockAfterCode()
    {
        $this->markTestIncomplete();
    }
    
    /**
     * Tests unlock() is called after an exception.
     *
     * @test
     */
    public function testUnlockAfterException()
    {
        $this->markTestIncomplete();
    }
    
    /**
     * Tests unlock() fails after the code was executed.
     *
     * @test
     * @expectedException malkusch\lock\exception\LockReleaseException
     */
    public function testUnlockFailsAfterCode()
    {
        $this->markTestIncomplete();
    }
    
    /**
     * Tests unlock() fails after the code threw an exception.
     *
     * The previous exception should be the code's exception.
     *
     * @test
     * @expectedException malkusch\lock\exception\LockReleaseException
     */
    public function testUnlockFailsAfterException()
    {
        $this->markTestIncomplete();
    }
}
