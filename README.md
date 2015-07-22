# Lock

This library helps executing critical code in concurrent situations.

# Installation

Use [Composer](https://getcomposer.org/):

```sh
composer require malkusch/lock
```

# Usage

The package is in the namespace
[`malkusch\lock`](http://malkusch.github.io/lock/api/namespace-malkusch.lock.html).

## Example

```php
<?php

use malkusch\lock\Flock;

$mutex = new Flock(fopen(__FILE__, "r"));
$mutex->synchronized(function () {
    // run locked code
});
```

# License and authors

This project is free and under the WTFPL.
Responsible for this project is Markus Malkusch markus@malkusch.de.

## Donations

If you like this project and feel generous donate a few Bitcoins here:
[1335STSwu9hST4vcMRppEPgENMHD2r1REK](bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK)

[![Build Status](https://travis-ci.org/malkusch/lock.svg?branch=master)](https://travis-ci.org/malkusch/lock)
