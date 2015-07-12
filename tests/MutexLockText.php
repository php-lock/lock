<?php

namespace malkusch\lock;

use org\bovigo\vfs\vfsStream;

/**
 * Tests for locking in Mutex.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 * @see Mutex
 */
class MutexLockText extends \PHPUnit_Framework_TestCase
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
                return new TransactionalMutex(new \PDO("sqlite::memory:"));
            }],

            [function () {
                vfsStream::setup("test");
                return new Flock(fopen(vfsStream::url("test/lock", "w")));
            }],
        ];
    }
    
    /**
     * Tests that two processes run sequentially locked code.
     *
     * @param callable $mutexFactory The Mutex factory.
     * @test
     * @dataProvider provideMutexFactories
     */
    public function testSerialisation(callable $mutexFactory)
    {
        $this->markTestIncomplete();
    }
}
