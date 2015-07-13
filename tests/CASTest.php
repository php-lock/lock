<?php

namespace malkusch\lock;

/**
 * Tests for CAS.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see CAS
 */
class CASTest extends \PHPUnit_Framework_TestCase
{

    /**
     * Tests exceeding the execution timeout.
     *
     * @test
     * @expectedException malkusch\lock\MutexException
     */
    public function testExceedTimeout()
    {
        $this->markTestIncomplete();
    }

    /**
     * Tests that an exception would stop any further iteration.
     *
     * @test
     * @expectedException \DomainException
     */
    public function testExceptionStopsIteration()
    {
        $this->markTestIncomplete();
    }

    /**
     * Tests notify() will stop the iteration and return the result.
     *
     * @test
     */
    public function testNotify()
    {
        $this->markTestIncomplete();
    }

    /**
     * Tests that the code is executed more times.
     *
     * @test
     */
    public function testIteration()
    {
        $this->markTestIncomplete();
    }
}
