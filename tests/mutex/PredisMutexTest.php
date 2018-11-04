<?php

namespace malkusch\lock\mutex;

use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\ClientInterface;

/**
 * Tests for PredisMutex.
 *
 * These tests require the environment variable:
 *
 * REDIS_URIS - a comma separated list of redis:// URIs.
 *
 * @author  Markus Malkusch <markus@malkusch.de>
 * @link    bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 * @see     PredisMutex
 * @group   redis
 */
class PredisMutexTest extends TestCase
{
    /**
     * @var ClientInterface
     */
    protected $client;

    protected function setUp()
    {
        parent::setUp();

        $config = $this->getPredisConfig();

        $this->client = new Client($config);

        if (is_string($config)) {
            $this->client->flushall(); // Clear any existing locks
        }
    }

    private function getPredisConfig()
    {
        if (getenv("REDIS_URIS") === false) {
            return "redis://localhost:6379";
        }

        $servers = explode(",", getenv("REDIS_URIS"));

        return array_map(
            function ($redisUri) {
                return str_replace("redis://", "tcp://", $redisUri);
            },
            $servers
        );
    }

    /**
     * Tests add() fails.
     *
     * @expectedException \malkusch\lock\exception\LockAcquireException
     */
    public function testAddFails()
    {
        $client = new Client("redis://127.0.0.1:12345");

        $mutex  = new PredisMutex([$client], "test");

        $mutex->synchronized(
            function () {
                $this->fail("Code execution is not expected");
            }
        );
    }

    public function testWorksNormally()
    {
        $mutex = new PredisMutex([$this->client], "test");

        $mutex->synchronized(function(): void {
            $this->expectNotToPerformAssertions();
        });
    }

    /**
     * Tests evalScript() fails.
     *
     * @expectedException \malkusch\lock\exception\LockReleaseException
     */
    public function testEvalScriptFails()
    {
        $this->markTestIncomplete();
    }
}
