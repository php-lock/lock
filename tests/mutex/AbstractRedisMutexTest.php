<?php

namespace malkusch\lock\mutex;

/**
 * Tests for AbstractRedisMutex and its implementations.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 *
 * @see AbstractRedisMutex
 * @see PHPRedisMutex
 * @see PredisMutex
 */
class AbstractRedisMutexTest extends \PHPUnit_Framework_TestCase
{

    /**
     * Tests seeding produces different tokens for each process.
     *
     * @test
     */
    public function testSeedRandom()
    {
        $this->markTestIncomplete();
    }
    
    /**
     * TODO define tests for AbstractRedisMutex and its implementations.
     *
     * @test
     */
    public function test()
    {
        $this->markTestIncomplete();
    }
}
