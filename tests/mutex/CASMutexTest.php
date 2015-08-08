<?php

namespace malkusch\lock\mutex;

use phpmock\phpunit\PHPMock;
use phpmock\environment\SleepEnvironmentBuilder;

/**
 * Tests for CASMutex.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see CASMutex
 */
class CASMutexTest extends \PHPUnit_Framework_TestCase
{

    use PHPMock;
    
    protected function setUp()
    {
        parent::setUp();
        
        $builder = new SleepEnvironmentBuilder();
        $builder->addNamespace(__NAMESPACE__);
        $builder->addNamespace('malkusch\lock\util');
        $sleep = $builder->build();
        $sleep->enable();
        
        $this->registerForTearDown($sleep);
    }

    /**
     * Tests exceeding the execution timeout.
     *
     * @test
     * @expectedException malkusch\lock\exception\LockAcquireException
     */
    public function testExceedTimeout()
    {
        $mutex = new CASMutex(1);
        $mutex->synchronized(function () {
            sleep(2);
        });
    }

    /**
     * Tests that an exception would stop any further iteration.
     *
     * @test
     * @expectedException \DomainException
     */
    public function testExceptionStopsIteration()
    {
        $mutex = new CASMutex();
        $mutex->synchronized(function () {
            throw new \DomainException();
        });
    }

    /**
     * Tests notify() will stop the iteration and return the result.
     *
     * @test
     */
    public function testNotify()
    {
        $i     = 0;
        $mutex = new CASMutex();
        $mutex->synchronized(function () use ($mutex, &$i) {
            $i++;
            $mutex->notify();
        });
        $this->assertEquals(1, $i);
    }

    /**
     * Tests that the code is executed more times.
     *
     * @test
     */
    public function testIteration()
    {
        $i     = 0;
        $mutex = new CASMutex();
        $mutex->synchronized(function () use ($mutex, &$i) {
            $i++;
            if ($i > 1) {
                $mutex->notify();
            }
        });
        $this->assertEquals(2, $i);
    }
}
