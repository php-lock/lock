<?php

namespace malkusch\lock\mutex;

use Redis;
use RedisException;

/**
 * Tests for PHPRedisMutex.
 *
 * These tests require the environment variable:
 *
 * REDIS_URIS - a comma separated list of redis:// URIs.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 * @see PHPRedisMutex
 * @requires redis
 * @group redis
 */
class PHPRedisMutexTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Redis The Redis API.
     */
    private $redis;
    
    /**
     * @var PHPRedisMutex The SUT.
     */
    private $mutex;
    
    protected function setUp()
    {
        parent::setUp();
        
        $this->redis = new Redis();

        $uris = explode(",", getenv("REDIS_URIS") ?: "redis://localhost");
        $uri  = parse_url($uris[0]);
        if (!empty($uri["port"])) {
            $this->redis->connect($uri["host"], $uri["port"]);
        } else {
            $this->redis->connect($uri["host"]);
        }

        $this->redis->flushAll(); // Clear any existing locks.

        $this->mutex = new PHPRedisMutex([$this->redis], "test");
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
        $this->redis->close();
        $this->mutex->synchronized(function () {
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
        $this->mutex->synchronized(function () {
            $this->redis->close();
        });
    }

    /**
     * @param $serialization
     * @dataProvider dpSerializationModes
     */
    public function testSyncronizedWorks($serialization)
    {
        $this->redis->setOption(Redis::OPT_SERIALIZER, $serialization);

        $this->mutex->synchronized(function () {
            $this->assertTrue(true);
        });
    }

    public function dpSerializationModes() {
        return [
            [Redis::SERIALIZER_NONE],
            [Redis::SERIALIZER_PHP],
            [Redis::SERIALIZER_IGBINARY],
        ];
    }
}
