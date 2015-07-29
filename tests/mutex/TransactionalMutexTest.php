<?php

namespace malkusch\lock\mutex;

/**
 * Tests for TransactionalMutexTest.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see TransactionalMutexTest
 */
class TransactionalMutexTestTest extends \PHPUnit_Framework_TestCase
{

    /**
     * Tests building the mutex with an invalid error mode.
     *
     * @test
     */
    public function testInvalidErrorMode()
    {
        $this->markTestIncomplete();
    }

    /**
     * Tests BEGIN fails.
     *
     * @test
     */
    public function testBeginFails()
    {
        $this->markTestIncomplete();
    }

    /**
     * Tests that an exception in the critical code causes a ROLLBACK.
     *
     * @test
     */
    public function testExceptionRollsback()
    {
        $this->markTestIncomplete();
    }

    /**
     * Tests that a ROLLBACK caused by an exception fails.
     *
     * @test
     */
    public function testFailExceptionRollsback()
    {
        $this->markTestIncomplete();
    }
    
    /**
     * Tests replaying the transaction.
     *
     * @test
     */
    public function testReplayTransaction()
    {
        $this->markTestIncomplete();
    }
    
    /**
     * Tests failing a ROLLBACK after the failed COMMIT.
     *
     * @test
     */
    public function testRollbackAfterFailedCommitFails()
    {
        $this->markTestIncomplete();
    }
}
