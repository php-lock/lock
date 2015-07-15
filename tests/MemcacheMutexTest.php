<?php

namespace malkusch\lock;

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
     * Tests failing to release a lock.
     *
     * @test
     */
    public function testFailReleasingLock()
    {
        $this->markTestIncomplete();
    }
}
