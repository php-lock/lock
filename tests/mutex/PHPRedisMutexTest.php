<?php

namespace malkusch\lock\mutex;

use PHPUnit\Framework\TestCase;
use Redis;

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
class PHPRedisMutexTest extends TestCase
{
    /**
     * @var Redis[]
     */
    private $connections = [];

    /**
     * @var PHPRedisMutex The SUT.
     */
    private $mutex;
    
    protected function setUp()
    {
        parent::setUp();
        
        $uris = explode(",", getenv("REDIS_URIS") ?: "redis://localhost");

        foreach ($uris as $redisUri) {
            $uri  = parse_url($redisUri);

            $connection = new Redis();

            if (!empty($uri["port"])) {
                $connection->connect($uri["host"], $uri["port"]);
            } else {
                $connection->connect($uri["host"]);
            }

            $connection->flushAll(); // Clear any existing locks.

            $this->connections[] = $connection;
        }

        $this->mutex = new PHPRedisMutex($this->connections, "test");
    }

    private function closeMajorityConnections()
    {
        $numberToClose = ceil(count($this->connections) / 2);

        foreach (array_rand($this->connections, $numberToClose) as $keyToClose) {
            $this->connections[$keyToClose]->close();
        }
    }

    private function closeMinortyConnections()
    {
        $numberToClose = ceil(count($this->connections) / 2) - 1;

        foreach ((array) array_rand($this->connections, $numberToClose) as $keyToClose) {
            $this->connections[$keyToClose]->close();
        }
    }

    /**
     * @expectedException \malkusch\lock\exception\LockAcquireException
     * @expectedExceptionCode \malkusch\lock\exception\MutexException::REDIS_NOT_ENOUGH_SERVERS
     */
    public function testAddFails()
    {
        $this->closeMajorityConnections();

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
            $this->closeMajorityConnections();
        });
    }

    /**
     * @param $serialization
     * @dataProvider dpSerializationModes
     */
    public function testSynchronizedWorks($serialization)
    {
        foreach ($this->connections as $connection) {
            $connection->setOption(Redis::OPT_SERIALIZER, $serialization);
        }

        $this->assertNull($this->mutex->synchronized(function () {
            return null;
        }));
    }

    public function testResistantToPartialClusterFailuresForAcquiringLock()
    {
        $this->closeMinortyConnections();

        $this->assertNull($this->mutex->synchronized(function () {
            return null;
        }));
    }

    public function testResistantToPartialClusterFailuresForReleasingLock()
    {
        $this->assertNull($this->mutex->synchronized(function () {
            $this->closeMinortyConnections();
            return null;
        }));
    }

    public function dpSerializationModes()
    {
        if (!class_exists(Redis::class)) {
            return [];
        }

        $serializers = [
            [Redis::SERIALIZER_NONE],
            [Redis::SERIALIZER_PHP],
        ];

        if (defined("Redis::SERIALIZER_IGBINARY")) {
            $serializers[] = [constant("Redis::SERIALIZER_IGBINARY")];
        }

        return $serializers;
    }
}
