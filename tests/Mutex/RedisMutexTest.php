<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Exception\MutexException;
use Malkusch\Lock\Mutex\DistributedMutex;
use Malkusch\Lock\Mutex\RedisMutex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

if (\PHP_MAJOR_VERSION >= 8) {
    trait RedisCompatibilityTrait
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
    trait RedisCompatibilityTrait
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
 * These tests require the environment variable:
 *
 * REDIS_URIS - a comma separated list of redis:// URIs.
 *
 * @requires extension redis
 */
#[RequiresPhpExtension('redis')]
class RedisMutexTest extends TestCase
{
    /** @var \Redis[] */
    private $connections = [];

    /** @var RedisMutex */
    private $mutex;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (!getenv('REDIS_URIS')) {
            self::markTestSkipped('Redis server is required');
        }

        $redisUris = explode(',', getenv('REDIS_URIS'));

        foreach ($redisUris as $redisUri) {
            $uri = parse_url($redisUri);

            // original Redis::set and Redis::eval calls will reopen the connection
            $connection = new class extends \Redis {
                use RedisCompatibilityTrait;

                private bool $isClosed = false;

                #[\Override]
                public function close(): bool
                {
                    $res = parent::close();
                    $this->isClosed = true;

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
                    if ($this->isClosed) {
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
                    if ($this->isClosed) {
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

        $this->mutex = new DistributedMutex(array_map(static fn ($v) => new RedisMutex($v, 'test'), $this->connections)); // @phpstan-ignore assign.propertyType
    }

    #[\Override]
    protected function assertPostConditions(): void
    {
        // workaround for burn testing
        $this->connections = [];

        parent::assertPostConditions();
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
        $this->expectExceptionCode(MutexException::CODE_REDLOCK_NOT_ENOUGH_SERVERS);

        $this->closeMajorityConnections();

        $this->mutex->synchronized(static function (): void {
            self::fail();
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
    #[DataProvider('provideSerializersAndCompressorsCases')]
    public function testSerializersAndCompressors(int $serializer, int $compressor): void
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
            return;
        }

        yield [\Redis::SERIALIZER_NONE, \Redis::COMPRESSION_NONE];
        yield [\Redis::SERIALIZER_PHP, \Redis::COMPRESSION_NONE];

        if (defined('Redis::SERIALIZER_IGBINARY') && extension_loaded('igbinary')) {
            yield [
                constant('Redis::SERIALIZER_IGBINARY'),
                \Redis::COMPRESSION_NONE,
            ];
        }

        if (defined('Redis::COMPRESSION_LZF') && extension_loaded('lzf')) {
            yield [
                \Redis::SERIALIZER_NONE,
                constant('Redis::COMPRESSION_LZF'),
            ];
            yield [
                \Redis::SERIALIZER_PHP,
                constant('Redis::COMPRESSION_LZF'),
            ];

            if (defined('Redis::SERIALIZER_IGBINARY') && extension_loaded('igbinary')) {
                yield [
                    constant('Redis::SERIALIZER_IGBINARY'),
                    constant('Redis::COMPRESSION_LZF'),
                ];
            }
        }
    }
}
