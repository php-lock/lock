<?php

namespace malkusch\lock;

/**
 * Tests for locking in Mutex.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see Mutex
 */
class MutexLockTest extends \PHPUnit_Framework_TestCase
{
    
    /**
     * Provides Mutex factories.
     *
     * @return callable[][] The mutex factories.
     */
    public function provideMutexFactories()
    {
        $lockFile = stream_get_meta_data(tmpfile())["uri"];
        return [
            [function () use ($lockFile) {
                return new Flock(fopen($lockFile, "w"));
            }],
            [function () {
                return new Semaphore(ftok(__FILE__, "b"));
            }],
        ];
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
        $this->assertTrue(abs($delta - 1) < 0.1);
    }
}
