<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests;

use phpmock\environment\SleepEnvironmentBuilder;

// workaround https://github.com/php/php-src/issues/17116
(static function () {
    $sleepBuilder = new SleepEnvironmentBuilder();
    $sleepBuilder->addNamespace('Malkusch\Lock\Mutex');
    $sleepBuilder->addNamespace('Malkusch\Lock\Util');
    $sleepBuilder->addNamespace('Malkusch\Lock\Tests\Mutex');
    $sleepBuilder->addNamespace('Malkusch\Lock\Tests\Util');
    $sleep = $sleepBuilder->build();
    $sleep->enable();
    $sleep->disable();
})();
