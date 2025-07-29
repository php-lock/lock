**[Requirements](#requirements)** |
**[Installation](#installation)** |
**[Usage](#usage)** |
**[Implementations](#implementations)** |
**[Authors](#authors)** |
**[License](#license)**

# php-lock/lock

[![Latest Stable Version](https://poser.pugx.org/malkusch/lock/version)](https://packagist.org/packages/malkusch/lock)
[![Total Downloads](https://poser.pugx.org/malkusch/lock/downloads)](https://packagist.org/packages/malkusch/lock/stats)
[![Build Status](https://github.com/php-lock/lock/actions/workflows/test-unit.yml/badge.svg?branch=master)](https://github.com/php-lock/lock/actions?query=branch:master)
[![Coverage](https://codecov.io/gh/php-lock/lock/branch/master/graph/badge.svg)](https://codecov.io/gh/php-lock/lock)
[![License](https://poser.pugx.org/malkusch/lock/license)](https://packagist.org/packages/malkusch/lock)

This library helps executing critical code in concurrent situations in serialized fashion.

php-lock/lock follows [semantic versioning][1].

----

## Requirements

 - PHP 7.4 - 8.4
 - Optionally [nrk/predis][2] to use the Predis locks.
 - Optionally the [php-pcntl][3] extension to enable locking with `flock()`
   without busy waiting in CLI scripts.
 - Optionally `flock()`, `ext-redis`, `ext-pdo_mysql`, `ext-pdo_sqlite`,
   `ext-pdo_pgsql` or `ext-memcached` can be used as a backend for locks. See
   examples below.
 - If `ext-redis` is used for locking and is configured to use igbinary for
   serialization or lzf for compression, additionally `ext-igbinary` and/or
   `ext-lzf` have to be installed.

----

## Installation

### Composer

To use this library through [composer][4], run the following terminal command
inside your repository's root folder.

```sh
composer require malkusch/lock
```

## Usage

This library uses the namespace `Malkusch\Lock`.

### Mutex

The [`Malkusch\Lock\Mutex\Mutex`][5] interface provides the base API for this library.

### Mutex::synchronized()

[`Malkusch\Lock\Mutex\Mutex::synchronized()`][6] executes code exclusively. This
method guarantees that the code is only executed by one process at once. Other
processes have to wait until the mutex is available. The critical code may throw
an exception, which would release the lock as well.

This method returns whatever is returned to the given callable. The return
value is not checked, thus it is up to the user to decide if for example the
return value `false` or `null` should be seen as a failed action.

Example:

```php
$newBalance = $mutex->synchronized(static function () use ($bankAccount, $amount) {
    $balance = $bankAccount->getBalance();
    $balance -= $amount;
    if ($balance < 0) {
        throw new \DomainException('You have no credit');
    }
    $bankAccount->setBalance($balance);

    return $balance;
});
```

### Mutex::check()

[`Malkusch\Lock\Mutex\Mutex::check()`][7] sets a callable, which will be
executed when [`Malkusch\Lock\Util\DoubleCheckedLocking::then()`][8] is called,
and performs a double-checked locking pattern, where it's return value decides
if the lock needs to be acquired and the synchronized code to be executed.

See [https://en.wikipedia.org/wiki/Double-checked_locking][9] for a more
detailed explanation of that feature.

If the check's callable returns `false`, no lock will be acquired and the
synchronized code will not be executed. In this case the
[`Malkusch\Lock\Util\DoubleCheckedLocking::then()`][8] method, will also return
`false` to indicate that the check did not pass either before or after acquiring
the lock.

In the case where the check's callable returns a value other than `false`, the
[`Malkusch\Lock\Util\DoubleCheckedLocking::then()`][8] method, will
try to acquire the lock and on success will perform the check again. Only when
the check returns something other than `false` a second time, the synchronized
code callable, which has been passed to `then()` will be executed. In this case
the return value of `then()` will be what ever the given callable returns and
thus up to the user to return `false` or `null` to indicate a failed action as
this return value will not be checked by the library.

Example:

```php
$newBalance = $mutex->check(static function () use ($bankAccount, $amount): bool {
    return $bankAccount->getBalance() >= $amount;
})->then(static function () use ($bankAccount, $amount) {
    $balance = $bankAccount->getBalance();
    $balance -= $amount;
    $bankAccount->setBalance($balance);

    return $balance;
});
```

### LockReleaseException::getCode{Exception, Result}()

Mutex implementations based on [`Malkush\Lock\Mutex\AbstractLockMutex`][10] will throw
[`Malkusch\Lock\Exception\LockReleaseException`][11] in case of lock release
problem, but the synchronized code block will be already executed at this point.
In order to read the code result (or an exception thrown there),
`LockReleaseException` provides methods to extract it.

Example:
```php
try {
    $result = $mutex->synchronized(static function () {
        if (someCondition()) {
            throw new \DomainException();
        }

        return 'result';
    });
} catch (LockReleaseException $e) {
    if ($e->getCodeException() !== null) {
        // do something with the $e->getCodeException() exception
    } else {
        // do something with the $e->getCodeResult() result
    }

    throw $e;
}
```

## Implementations

You can choose from one of the provided [`Malkusch\Lock\Mutex\Mutex`](#mutex) interface
implementations or create/extend your own implementation.

- [`FlockMutex`](#flockmutex)
- [`MemcachedMutex`](#memcachedmutex)
- [`RedisMutex`](#redismutex)
- [`SemaphoreMutex`](#semaphoremutex)
- [`MySQLMutex`](#mysqlmutex)
- [`PostgreSQLMutex`](#postgresqlmutex)
- [`DistributedMutex`](#distributedmutex)

### FlockMutex

The **FlockMutex** is a lock implementation based on
[`flock()`](https://php.net/manual/en/function.flock.php).

Example:
```php
$mutex = new FlockMutex(fopen(__FILE__, 'r'));
```

Timeouts are supported as an optional second argument. This uses the `ext-pcntl`
extension if possible or busy waiting if not.

### MemcachedMutex

The **MemcachedMutex** is a spinlock implementation which uses the
[`Memcached` extension](https://php.net/manual/en/book.memcached.php).

Example:
```php
$memcached = new \Memcached();
$memcached->addServer('localhost', 11211);

$mutex = new MemcachedMutex('balance', $memcached);
```

### RedisMutex

The **RedisMutex** is a lock implementation which supports the
[`phpredis` extension](https://github.com/phpredis/phpredis)
or [`Predis` API](https://github.com/nrk/predis) clients.

Both Redis and Valkey servers are supported.

If used with a cluster of Redis servers, acquiring and releasing locks will
continue to function as long as a majority of the servers still works.

Example:
```php
$redis = new \Redis();
$redis->connect('localhost');
// OR $redis = new \Predis\Client('redis://localhost');

$mutex = new RedisMutex($redis, 'balance');
```

### SemaphoreMutex

The **SemaphoreMutex** is a lock implementation based on
[Semaphore](https://php.net/manual/en/ref.sem.php).

Example:
```php
$semaphore = sem_get(ftok(__FILE__, 'a'));
$mutex = new SemaphoreMutex($semaphore);
```

### MySQLMutex

The **MySQLMutex** uses MySQL's
[`GET_LOCK`](https://dev.mysql.com/doc/refman/9.0/en/locking-functions.html#function_get-lock)
function.

Both MySQL and MariaDB servers are supported.

It supports timeouts. If the connection to the database server is lost or
interrupted, the lock is automatically released.

Note that before MySQL 5.7.5 you cannot use nested locks, any new lock will
silently release already held locks. You should probably refrain from using this
mutex on MySQL versions < 5.7.5.

Also note that `GET_LOCK` function is server wide and the MySQL manual suggests
you to namespace your locks like `dbname.lockname`.

```php
$pdo = new \PDO('mysql:host=localhost;dbname=test', 'username');
$mutex = new MySQLMutex($pdo, 'balance', 15);
```

### PostgreSQLMutex

The **PostgreSQLMutex** uses PostgreSQL's
[advisory locking](https://www.postgresql.org/docs/9.4/static/functions-admin.html#FUNCTIONS-ADVISORY-LOCKS)
functions.

Named locks are offered. PostgreSQL locking functions require integers but the
conversion is handled automatically.

It supports timeouts. If the connection to the database server is lost or
interrupted, the lock is automatically released.

```php
$pdo = new \PDO('pgsql:host=localhost;dbname=test', 'username');
$mutex = new PostgreSQLMutex($pdo, 'balance');
```

### DistributedMutex

The **DistributedMutex** is the distributed lock implementation of
[RedLock](https://redis.io/topics/distlock#the-redlock-algorithm) which supports
one or more [`Malkush\Lock\Mutex\AbstractSpinlockMutex`][10] instances.

Example:
```php
$mutex = new DistributedMutex([
    new \Predis\Client('redis://10.0.0.1'),
    new \Predis\Client('redis://10.0.0.2'),
], 'balance');
```

## Authors

Since year 2015 the development was led by Markus Malkusch, Willem Stuursma-Ruwen and many GitHub contributors.

Currently this library is maintained by Michael Voříšek - [GitHub](https://github.com/mvorisek) | [LinkedIn](https://www.linkedin.com/in/mvorisek/).

Commercial support is available.

## License

This project is free and is licensed under the MIT.

[1]: https://semver.org/
[2]: https://github.com/nrk/predis
[3]: https://php.net/manual/en/book.pcntl.php
[4]: https://getcomposer.org/
[5]: https://github.com/php-lock/lock/blob/3ca295ccda/src/Mutex/Mutex.php#L15
[6]: https://github.com/php-lock/lock/blob/3ca295ccda/src/Mutex/Mutex.php#L38
[7]: https://github.com/php-lock/lock/blob/3ca295ccda/src/Mutex/Mutex.php#L60
[8]: https://github.com/php-lock/lock/blob/3ca295ccda/src/Util/DoubleCheckedLocking.php#L61
[9]: https://en.wikipedia.org/wiki/Double-checked_locking
[10]: https://github.com/php-lock/lock/blob/3ca295ccda/src/Mutex/AbstractLockMutex.php
[11]: https://github.com/php-lock/lock/blob/3ca295ccda/src/Exception/LockReleaseException.php
[12]: https://github.com/php-lock/lock/blob/41509dda0a/src/Mutex/AbstractSpinlockMutex.php#L15
