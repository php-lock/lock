<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests\Mutex;

use phpmock\environment\SleepEnvironmentBuilder;
use phpmock\MockEnabledException;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\TestCase;

/**
 * This class is currently needed to pass the tests in CI.
 *
 * I do not know why yet. TODO remove it asap.
 */
class FixCiTest extends TestCase
{
    use PHPMock;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $sleepBuilder = new SleepEnvironmentBuilder();
        $sleepBuilder->addNamespace(__NAMESPACE__);
        $sleepBuilder->addNamespace('Malkusch\Lock\Mutex');
        $sleepBuilder->addNamespace('Malkusch\Lock\Util');
        $sleep = $sleepBuilder->build();
        try {
            $sleep->enable();
            $this->registerForTearDown($sleep);
        } catch (MockEnabledException $e) {
            // workaround for burn testing
            \assert($e->getMessage() === 'microtime is already enabled. Call disable() on the existing mock.');
        }
    }

    public function testDummy(): void
    {
        self::assertTrue(microtime(true) > 1.0);
    }
}
