# Mutext

# Installation

Use [Composer](https://getcomposer.org/):

```sh
composer require malkusch/mutext
```

# Usage

The package is in the namespace
[`malkusch\lock`](http://malkusch.github.io/mutext/api/namespace-malkusch.lock.html).

## Example

```php
<?php

use malkusch\lock\Flock;

$handle = fopen(__DIR__ . "/lock", "w");
$mutex  = new Flock($handle);
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
