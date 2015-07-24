<?php

namespace malkusch\lock\mutex;

/**
 * Tests for locking in Mutex.
 *
 * If you want to run memcache tests you should provide this environment variable:
 *
 * - MEMCACHE_HOST
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see Mutex
 */
class MutexLockTest extends \PHPUnit_Framework_TestCase
{
    
    /**
     * Tests high concurrency empirically.
     *
     * @param callable $code         The counter code.
     * @param callable $mutexFactory The mutex factory.
     * @param int      $concurrency  The number of processes.
     *
     * @test
     * @dataProvider provideTestHighConcurrency
     */
    public function testHighConcurrency(callable $code, callable $mutexFactory, $concurrency)
    {
        $isChild = false;
        $pids    = [];
        for ($i = 0; $i < $concurrency; $i++) {
            $pid     = pcntl_fork();
            $isChild = $pid == 0;
            if ($isChild) {
                break;

            }
            $pids[] = $pid;
        }
        
        // Concurrent increment.
        if ($isChild) {
            $mutex = call_user_func($mutexFactory);
            $mutex->synchronized(function () use ($code) {
                call_user_func($code, 1);
            });
            exit();
            
        }
        
        // Wait for all children.
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);

        }
        
        $counter = call_user_func($code, 0);
        $this->assertEquals($concurrency, $counter);
    }
    
    /**
     * Returns test cases for testHighConcurrency().
     *
     * @return array The test cases.
     */
    public function provideTestHighConcurrency()
    {
        return array_map(function (array $mutexFactory) {
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
                $mutexFactory[0],
                100
            ];
            
        }, $this->provideMutexFactories());
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
        $isChild   = pcntl_fork() == 0;
        
        $mutex = call_user_func($mutexFactory);
        $mutex->synchronized(function () {
            usleep(500000);
        });
        
        // exit the child.
        $isChild ? exit() : pcntl_wait($status);

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
            "flock" => [function () use ($path) {
                $file = fopen($path, "w");
                return new FlockMutex($file);
            }],
            "semaphore" => [function () use ($path) {
                $semaphore = sem_get(ftok($path, "b"));
                $this->assertTrue(is_resource($semaphore));
                return new SemaphoreMutex($semaphore);
            }],
        ];
        if (getenv("MEMCACHE_HOST")) {
            $cases["memcache"] = [function () {
                $memcache = new \Memcache();
                $memcache->connect(getenv("MEMCACHE_HOST"));
                return new MemcacheMutex("test", $memcache);
            }];
            $cases["memcached"] = [function () {
                $memcached = new \Memcached();
                $memcached->addServer(getenv("MEMCACHE_HOST"), 11211);
                return new MemcachedMutex("test", $memcached);
            }];
        }
        return $cases;
    }
}
