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

            // original Redis::set and Redis::eval calls will reopen the connection
            $connection = new class extends Redis {
                private $is_closed = false;

                public function close()
                {
                    parent::close();
                    $this->is_closed = true;
                }

                public function set($key, $value, $timeout = 0)
                {
                    if ($this->is_closed) {
                        throw new \RedisException('Connection is closed');
                    }

                    return parent::set($key, $value, $timeout);
                }

                public function eval($script, $args = array(), $numKeys = 0)
                {
                    if ($this->is_closed) {
                        throw new \RedisException('Connection is closed');
                    }

                    return parent::eval($script, $args, $numKeys);
                }
            };

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

        foreach ((array) array_rand($this->connections, $numberToClose) as $keyToClose) {
            $this->connections[$keyToClose]->close();
        }
    }

    private function closeMinorityConnections()
    {
        if (count($this->connections) === 1) {
            $this->markTestSkipped("Cannot test this with only a single Redis server");
        }

        $numberToClose = ceil(count($this->connections) / 2) - 1;
        if (0 >= $numberToClose) {
            return;
        }

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

        $this->mutex->synchronized(function (): void {
            $this->fail("Code execution is not expected");
        });
    }

    /**
     * Tests evalScript() fails.
     *
     * @expectedException \malkusch\lock\exception\LockReleaseException
     */
    public function testEvalScriptFails()
    {
        $this->mutex->synchronized(function (): void {
            $this->closeMajorityConnections();
        });
    }

    /**
     * @dataProvider serializationAndCompressionModes
     */
    public function testSerializersAndCompressors($serializer, $compressor)
    {
        foreach ($this->connections as $connection) {
            $connection->setOption(Redis::OPT_SERIALIZER, $serializer);
            $connection->setOption(Redis::OPT_COMPRESSION, $compressor);
        }

        $this->assertSame(
            "test",
            $this->mutex->synchronized(function (): string {
                return "test";
            })
        );
    }

    public function testResistantToPartialClusterFailuresForAcquiringLock()
    {
        $this->closeMinorityConnections();

        $this->assertSame(
            "test",
            $this->mutex->synchronized(function (): string {
                return "test";
            })
        );
    }

    public function testResistantToPartialClusterFailuresForReleasingLock()
    {
        $this->assertNull($this->mutex->synchronized(function () {
            $this->closeMinorityConnections();
            return null;
        }));
    }

    public function serializationAndCompressionModes()
    {
        if (!class_exists(Redis::class)) {
            return [];
        }

        $options = [
            [Redis::SERIALIZER_NONE, Redis::COMPRESSION_NONE],
            [Redis::SERIALIZER_PHP, Redis::COMPRESSION_NONE],
        ];

        if (defined("Redis::SERIALIZER_IGBINARY")) {
            $options[] = [
                constant("Redis::SERIALIZER_IGBINARY"),
                Redis::COMPRESSION_NONE
            ];
        }

        if (defined("Redis::COMPRESSION_LZF")) {
            $options[] = [
                Redis::SERIALIZER_NONE,
                constant("Redis::COMPRESSION_LZF")
            ];
            $options[] = [
                Redis::SERIALIZER_PHP,
                constant("Redis::COMPRESSION_LZF")
            ];

            if (defined("Redis::SERIALIZER_IGBINARY")) {
                $options[] = [
                    constant("Redis::SERIALIZER_IGBINARY"),
                    constant("Redis::COMPRESSION_LZF")
                ];
            }
        }

        return $options;
    }
}
