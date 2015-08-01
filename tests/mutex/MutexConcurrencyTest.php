<?php

namespace malkusch\lock\mutex;

use Predis\Client;
use Redis;
use ezcSystemInfo;
use Spork\ProcessManager;

/**
 * Concurrency Tests for Mutex.
 *
 * If you want to run integration tests you should provide these environment variables:
 *
 * - MEMCACHE_HOST
 * - REDIS_URIS - a comma separated list of redis:// URIs.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see Mutex
 */
class MutexConcurrencyTest extends \PHPUnit_Framework_TestCase
{
    
    /**
     * Forks, runs code in the children and wait until all finished.
     *
     * @param int $concurrency The amount of forks.
     * @param callable $code The code for the fork.
     */
    private function fork($concurrency, callable $code)
    {
        $manager = new ProcessManager();
        for ($i = 0; $i < $concurrency; $i++) {
            $manager->fork($code);
        }
    }
    
    /**
     * Tests high contention empirically.
     *
     * @param callable $code         The counter code.
     * @param callable $mutexFactory The mutex factory.
     *
     * @test
     * @dataProvider provideTestHighContention
     */
    public function testHighContention(callable $code, callable $mutexFactory)
    {
        $concurrency = max(2, ezcSystemInfo::getInstance()->cpuCount);
        $iterations  = 20000 / $concurrency;
        $timeout = $concurrency * 20;
        
        $this->fork($concurrency, function () use ($mutexFactory, $timeout, $iterations, $code) {
            $mutex = call_user_func($mutexFactory, $timeout);
            for ($i = 0; $i < $iterations; $i++) {
                $mutex->synchronized(function () use ($code) {
                    call_user_func($code, 1);
                });

            }
        });
        
        $counter = call_user_func($code, 0);
        $this->assertEquals($concurrency * $iterations, $counter);
    }
    
    /**
     * Returns test cases for testHighContention().
     *
     * @return array The test cases.
     */
    public function provideTestHighContention()
    {
        $cases = array_map(function (array $mutexFactory) {
            $file = tmpfile();
            fputs($file, pack("i", 0));
            fflush($file);

            return [
                function ($increment) use ($file) {
                    fseek($file, 0);
                    $data = fread($file, 4);
                    $counter = unpack("i", $data)[1];

                    $counter += $increment;
                    
                    fseek($file, 0);
                    fwrite($file, pack("i", $counter));
                    fflush($file);
                    
                    return $counter;
                },
                $mutexFactory[0]
            ];
            
        }, $this->provideMutexFactories());
        
        return $cases;
    }
    
    /**
     * Tests that two processes run sequentially.
     *
     * @param callable $mutexFactory The Mutex factory.
     * @test
     * @dataProvider provideMutexFactories
     */
    public function testSerialisation(callable $mutexFactory)
    {
        $timestamp = microtime(true);
        
        $this->fork(2, function () use ($mutexFactory) {
            $mutex = call_user_func($mutexFactory);
            $mutex->synchronized(function () {
                usleep(500000);
            });
        });

        $delta = microtime(true) - $timestamp;
        $this->assertGreaterThan(1, $delta);
    }
    
    /**
     * Provides Mutex factories.
     *
     * @return callable[][] The mutex factories.
     */
    public function provideMutexFactories()
    {
        $path = stream_get_meta_data(tmpfile())["uri"];
        
        $cases = [
            "flock" => [function ($timeout = 3) use ($path) {
                $file = fopen($path, "w");
                return new FlockMutex($file);
            }],
                    
            "semaphore" => [function ($timeout = 3) use ($path) {
                $semaphore = sem_get(ftok($path, "b"));
                $this->assertTrue(is_resource($semaphore));
                return new SemaphoreMutex($semaphore);
            }],
        ];
            
        if (getenv("MEMCACHE_HOST")) {
            $cases["memcache"] = [function ($timeout = 3) {
                $memcache = new \Memcache();
                $memcache->connect(getenv("MEMCACHE_HOST"));
                return new MemcacheMutex("test", $memcache, $timeout);
            }];
            
            $cases["memcached"] = [function ($timeout = 3) {
                $memcached = new \Memcached();
                $memcached->addServer(getenv("MEMCACHE_HOST"), 11211);
                return new MemcachedMutex("test", $memcached, $timeout);
            }];
        }
        
        if (getenv("REDIS_URIS")) {
            $uris = explode(",", getenv("REDIS_URIS"));

            $cases["PredisMutex"] = [function ($timeout = 3) use ($uris) {
                $clients = array_map(
                    function ($uri) {
                        return new Client($uri);
                    },
                    $uris
                );
                return new PredisMutex($clients, "test", $timeout);
            }];

            $cases["PHPRedisMutex"] = [function ($timeout = 3) use ($uris) {
                $apis = array_map(
                    function ($uri) {
                        $redis = new Redis();
                        
                        $uri = parse_url($uri);
                        if (!empty($uri["port"])) {
                            $redis->connect($uri["host"], $uri["port"]);
                        } else {
                            $redis->connect($uri["host"]);
                        }
                        
                        return $redis;
                    },
                    $uris
                );
                return new PHPRedisMutex($apis, "test", $timeout);
            }];
        }
        
        return $cases;
    }
}
