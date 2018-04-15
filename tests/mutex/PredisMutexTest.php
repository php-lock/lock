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
 * @group redis
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
        
        $this->client = new Client($this->getPredisConfig());

        if (count($this->getPredisConfig()) == 1) {
            $this->client->flushall(); // Clear any existing locks
        }
    }

    private function getPredisConfig()
    {
        if (getenv("REDIS_URIS") === false) {
            return null;
        }

        $servers = explode(",", getenv("REDIS_URIS"));

        return array_map(function ($redisUri) {
            return str_replace("redis://", "tcp://", $redisUri);
        }, $servers);
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
        $client = new Client("redis://127.0.0.1:12345");

        $mutex  = new PredisMutex([$client], "test");
        
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
