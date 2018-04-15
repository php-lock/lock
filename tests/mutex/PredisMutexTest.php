<?php

namespace malkusch\lock\mutex;

use Predis\Client;
use Predis\ClientInterface;

/**
 * Tests for PredisMutex.
 *
 * These tests require the environment variable:
 *
 * REDIS_URIS - a comma separated list of redis:// URIs.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see PredisMutex
 */
class PredisMutexTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ClientInterface
     */
    protected $client;

    protected function setUp()
    {
        parent::setUp();
        
        if (!getenv("REDIS_URIS")) {
             $this->markTestSkipped();
        }

        $this->client = new Client("redis://example.net");
    }

    protected function tearDown()
    {
        $this->client->flushall();

        parent::tearDown();
    }

    /**
     * Tests add() fails.
     *
     * @test
     * @expectedException \malkusch\lock\exception\LockAcquireException
     * @expectedExceptionCode \malkusch\lock\exception\MutexException::REDIS_NOT_ENOUGH_SERVERS
     */
    public function testAddFails()
    {
        $mutex  = new PredisMutex([$this->client], "test");
        
        $mutex->synchronized(function () {
            $this->fail("Code execution is not expected");
        });
    }

    /**
     * Tests evalScript() fails.
     *
     * @test
     * @expectedException \malkusch\lock\exception\LockReleaseException
     */
    public function testEvalScriptFails()
    {
        $this->markTestIncomplete();
    }
}
