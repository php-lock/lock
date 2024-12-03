<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use malkusch\lock\exception\MutexException;
use PHPUnit\Framework\TestCase;

if (\PHP_MAJOR_VERSION >= 8) {
    trait RedisTestTrait
    {
        /**
         * @param list<mixed> $args
         */
        #[\Override] // @phpstan-ignore method.childParameterType
        public function eval($script, $args = [], $numKeys = 0): mixed
        {
            return $this->_eval($script, $args, $numKeys);
        }

        /**
         * @param mixed $options
         */
        #[\Override]
        public function set($key, $value, $options = null): /* \Redis|string| */ bool
        {
            return $this->_set($key, $value, $options);
        }
    }
} else {
    trait RedisTestTrait
    {
        /**
         * @return mixed
         */
        #[\Override]
        public function eval($script, $args = [], $numKeys = 0)
        {
            return $this->_eval($script, $args, $numKeys);
        }

        /**
         * @return \Redis|string|bool
         */
        #[\Override]
        public function set($key, $value, $options = null)
        {
            return $this->_set($key, $value, $options);
        }
    }
}

/**
 * Tests for PHPRedisMutex.
 *
 * These tests require the environment variable:
 *
 * REDIS_URIS - a comma separated list of redis:// URIs.
 *
 * @requires extension redis
 *
 * @group redis
 */
class PHPRedisMutexTest extends TestCase
{
    /** @var \Redis[] */
    private $connections = [];

    /** @var PHPRedisMutex the SUT */
    private $mutex;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $uris = explode(',', getenv('REDIS_URIS') ?: 'redis://localhost'); // @phpstan-ignore ternary.shortNotAllowed

        foreach ($uris as $redisUri) {
            $uri = parse_url($redisUri);

            // original Redis::set and Redis::eval calls will reopen the connection
            $connection = new class extends \Redis {
                use RedisTestTrait;

                /** @var bool */
                private $is_closed = false;

                #[\Override]
                public function close(): bool
                {
                    $res = parent::close();
                    $this->is_closed = true;

                    return $res;
                }

                /**
                 * @param mixed $value
                 * @param mixed $timeout
                 *
                 * @return \Redis|string|bool
                 */
                private function _set(string $key, $value, $timeout = 0)
                {
                    if ($this->is_closed) {
                        throw new \RedisException('Connection is closed');
                    }

                    return parent::set($key, $value, $timeout);
                }

                /**
                 * @param list<mixed> $args
                 *
                 * @return mixed
                 */
                private function _eval(string $script, array $args = [], int $numKeys = 0)
                {
                    if ($this->is_closed) {
                        throw new \RedisException('Connection is closed');
                    }

                    return parent::eval($script, $args, $numKeys);
                }
            };

            $connection->connect($uri['host'], $uri['port'] ?? 6379);
            if (!empty($uri['pass'])) {
                $connection->auth(
                    empty($uri['user'])
                    ? $uri['pass']
                    : [$uri['user'], $uri['pass']]
                );
            }

            $connection->flushAll(); // Clear any existing locks.

            $this->connections[] = $connection;
        }

        $this->mutex = new PHPRedisMutex($this->connections, 'test');
    }

    private function closeMajorityConnections(): void
    {
        $numberToClose = (int) ceil(count($this->connections) / 2);

        foreach ((array) array_rand($this->connections, $numberToClose) as $keyToClose) {
            $this->connections[$keyToClose]->close();
        }
    }

    private function closeMinorityConnections(): void
    {
        if (count($this->connections) === 1) {
            self::markTestSkipped('Cannot test this with only a single Redis server');
        }

        $numberToClose = (int) ceil(count($this->connections) / 2) - 1;
        if (0 >= $numberToClose) {
            return;
        }

        foreach ((array) array_rand($this->connections, $numberToClose) as $keyToClose) {
            $this->connections[$keyToClose]->close();
        }
    }

    public function testAddFails(): void
    {
        $this->expectException(LockAcquireException::class);
        $this->expectExceptionCode(MutexException::REDIS_NOT_ENOUGH_SERVERS);

        $this->closeMajorityConnections();

        $this->mutex->synchronized(static function (): void {
            self::fail('Code execution is not expected');
        });
    }

    /**
     * Tests evalScript() fails.
     */
    public function testEvalScriptFails(): void
    {
        $this->expectException(LockReleaseException::class);

        $this->mutex->synchronized(function (): void {
            $this->closeMajorityConnections();
        });
    }

    /**
     * @param \Redis::SERIALIZER_*  $serializer
     * @param \Redis::COMPRESSION_* $compressor
     *
     * @dataProvider provideSerializersAndCompressorsCases
     */
    public function testSerializersAndCompressors($serializer, $compressor): void
    {
        foreach ($this->connections as $connection) {
            $connection->setOption(\Redis::OPT_SERIALIZER, $serializer);
            $connection->setOption(\Redis::OPT_COMPRESSION, $compressor);
        }

        self::assertSame('test', $this->mutex->synchronized(static function (): string {
            return 'test';
        }));
    }

    public function testResistantToPartialClusterFailuresForAcquiringLock(): void
    {
        $this->closeMinorityConnections();

        self::assertSame('test', $this->mutex->synchronized(static function (): string {
            return 'test';
        }));
    }

    public function testResistantToPartialClusterFailuresForReleasingLock(): void
    {
        self::assertNull($this->mutex->synchronized(function () { // @phpstan-ignore staticMethod.alreadyNarrowedType
            $this->closeMinorityConnections();

            return null;
        }));
    }

    /**
     * @return iterable<list<mixed>>
     */
    public static function provideSerializersAndCompressorsCases(): iterable
    {
        if (!class_exists(\Redis::class)) {
            return [];
        }

        $options = [
            [\Redis::SERIALIZER_NONE, \Redis::COMPRESSION_NONE],
            [\Redis::SERIALIZER_PHP, \Redis::COMPRESSION_NONE],
        ];

        if (defined('Redis::SERIALIZER_IGBINARY')) {
            $options[] = [
                constant('Redis::SERIALIZER_IGBINARY'),
                \Redis::COMPRESSION_NONE,
            ];
        }

        if (defined('Redis::COMPRESSION_LZF')) {
            $options[] = [
                \Redis::SERIALIZER_NONE,
                constant('Redis::COMPRESSION_LZF'),
            ];
            $options[] = [
                \Redis::SERIALIZER_PHP,
                constant('Redis::COMPRESSION_LZF'),
            ];

            if (defined('Redis::SERIALIZER_IGBINARY')) {
                $options[] = [
                    constant('Redis::SERIALIZER_IGBINARY'),
                    constant('Redis::COMPRESSION_LZF'),
                ];
            }
        }

        return $options;
    }
}
