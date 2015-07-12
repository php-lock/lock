<?php

namespace malkusch\lock;

use org\bovigo\vfs\vfsStream;

/**
 * Tests for Mutex.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see Mutex
 */
class MutexTest extends \PHPUnit_Framework_TestCase
{

    /**
     * Provides Mutex factories.
     *
     * @return callable[][] The mutex factories.
     */
    public function provideMutexFactories()
    {
        return [
            [function () {
                return new NoMutex();
            }],
            [function () {
                return new TransactionalMutex(new \PDO("sqlite::memory:"));
            }],
            [function () {
                vfsStream::setup("test");
                return new Flock(fopen(vfsStream::url("test/lock"), "w"));
            }],
            [function () {
                return new Semaphore(ftok(__FILE__, "a"));
            }],
        ];
    }
    
    /**
     * Tests synchronized() executes the code and returns its result.
     *
     * @param callable $mutexFactory The Mutex factory.
     * @test
     * @dataProvider provideMutexFactories
     */
    public function testSynchronizedDelegates(callable $mutexFactory)
    {
        $mutex  = call_user_func($mutexFactory);
        $result = $mutex->synchronized(function () {
            return "test";

        });
        $this->assertEquals("test", $result);
    }
    
    /**
     * Tests synchronized() rethrows the exception of the code.
     *
     * @param callable $mutexFactory The Mutex factory.
     * @test
     * @dataProvider provideMutexFactories
     * @expectedException \DomainException
     */
    public function testSynchronizedPassesExceptionThrough(callable $mutexFactory)
    {
        $mutex = call_user_func($mutexFactory);
        $mutex->synchronized(function () {
            throw new \DomainException();

        });
    }
}
