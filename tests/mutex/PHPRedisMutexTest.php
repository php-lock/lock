<?php

namespace malkusch\lock\mutex;

use malkusch\lock\tests\classes\PHPRedisMutexWithoutSerialize;
use Redis;

/**
 * Tests for PHPRedisMutex.
 *
 * These tests require the environment variable:
 *
 * REDIS_URIS - a comma separated list of redis:// URIs.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see PHPRedisMutex
 * @requires redis
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
        
        if (!getenv("REDIS_URIS")) {
             $this->markTestSkipped();
             return;
        }
        $this->redis = new Redis();

        $uris = explode(",", getenv("REDIS_URIS"));
        $uri  = parse_url($uris[0]);
        if (!empty($uri["port"])) {
            $this->redis->connect($uri["host"], $uri["port"]);
        } else {
            $this->redis->connect($uri["host"]);
        }
        if (defined('Redis::SERIALIZER_IGBINARY') && extension_loaded('igbinary')) {
            $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
        } else {
            $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
        }
        $this->redis->flushAll(); // flush all locks from previously tests

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

    public function testSuccessGettingLock()
    {
        $executed = null;

        $this->mutex->synchronized(function () use (&$executed) {
            $executed = true;
        });

        $this->assertTrue($executed);
    }

    /**
     * @expectedException \malkusch\lock\exception\LockReleaseException
     */
    public function testFailedReleaseLockWithoutSerialize()
    {
        $mutexWithoutSerialize = new PHPRedisMutexWithoutSerialize([$this->redis], 'test');
        $mutexWithoutSerialize->synchronized(function () {
            // nothing
        });
    }

    public function testSuccessWithoutSerializeOption()
    {
        $executed = null;

        $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
        $mutexWithoutSerialize = new PHPRedisMutexWithoutSerialize([$this->redis], 'test');
        $mutexWithoutSerialize->synchronized(function () use (&$executed) {
            $executed = true;
        });

        $this->assertTrue($executed);
    }
}
