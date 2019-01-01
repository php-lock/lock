**[Requirements](#requirements)** |
**[Installation](#installation)** |
**[Usage](#usage)** |
**[License and authors](#license-and-authors)** |
**[Donations](#donations)**

# php-lock/lock

[![Latest Stable Version](https://poser.pugx.org/malkusch/lock/version)](https://packagist.org/packages/malkusch/lock)
[![Total Downloads](https://poser.pugx.org/malkusch/lock/downloads)](https://packagist.org/packages/malkusch/lock)
[![Latest Unstable Version](https://poser.pugx.org/malkusch/lock/v/unstable)](//packagist.org/packages/malkusch/lock)
[![Build Status](https://travis-ci.org/php-lock/lock.svg?branch=master)](https://travis-ci.org/php-lock/lock)
[![License](https://poser.pugx.org/malkusch/lock/license)](https://packagist.org/packages/malkusch/lock)

This library helps executing critical code in concurrent situations.

php-lock/lock follows semantic versioning. Read more on [semver.org][1].

----

## Requirements

 - PHP 7.1 or above
 - Optionally [nrk/predis][2] to use the Predis locks.
 - Optionally the [php-pcntl][3] extension to enable locking with `flock()` without
   busy waiting in CLI scripts.
 - Optionally `flock()`, `ext-redis`, `ext-pdo_mysql`, `ext-pdo_sqlite`, `ext-pdo_pgsql` or `ext-memcached` can be used as a backend for locks. See examples below.

----

## Installation

### Composer

To use this library through [composer][4], run the following terminal command
inside your repository's root folder.

```sh
composer require "malkusch/lock"
```

## Usage

This library uses the namespace `malkusch\lock`.

### Mutex

The [`malkusch\lock\mutex\Mutex`][5] class is an abstract class and provides the
base API for this library.

#### Mutex::synchronized()

[`malkusch\lock\mutex\Mutex::synchronized()`][6] executes code exclusively. This
method guarantees that the code is only executed by one process at once. Other
processes have to wait until the mutex is available. The critical code may throw
an exception, which would release the lock as well.

This method returns whatever is returned to the given callable. The return
value is not checked, thus it is up to the user to decide if for example the
return value `false` or `null` should be seen as a failed action.

Example:

```php
$newBalance = $mutex->synchronized(function () use ($bankAccount, $amount): int {
    $balance = $bankAccount->getBalance();
    $balance -= $amount;
    if ($balance < 0) {
        throw new \DomainException('You have no credit.');
    }
    $bankAccount->setBalance($balance);

    return $balance;
});
```

#### Mutex::check()

[`malkusch\lock\mutex\Mutex::check()`][7] sets a callable, which will be
executed when [`malkusch\lock\util\DoubleCheckedLocking::then()`][8] is called,
and performs a double-checked locking pattern, where it's return value decides
if the lock needs to be acquired and the synchronized code to be executed.

See [https://en.wikipedia.org/wiki/Double-checked_locking][9] for a more
detailed explanation of that feature.

If the check's callable returns `false`, no lock will be acquired and the
synchronized code will not be executed. In this case the
[`malkusch\lock\util\DoubleCheckedLocking::then()`][8] method, will also return
`false` to indicate that the check did not pass either before or after acquiring
the lock.

In the case where the check's callable returns a value other than `false`, the
[`malkusch\lock\util\DoubleCheckedLocking::then()`][8] method, will
try to acquire the lock and on success will perform the check again. Only when
the check returns something other than `false` a second time, the synchronized
code callable, which has been passed to `then()` will be executed. In this case
the return value of `then()` will be what ever the given callable returns and
thus up to the user to return `false` or `null` to indicate a failed action as
this return value will not be checked by the library.

Example:

```php
$newBalance = $mutex->check(function () use ($bankAccount, $amount): bool {
    return $bankAccount->getBalance() >= $amount;
})->then(function () use ($bankAccount, $amount): int {
    $balance = $bankAccount->getBalance();
    $balance -= $amount;
    $bankAccount->setBalance($balance);

    return $balance;
});

if (false === $newBalance) {
    if ($balance < 0) {
        throw new \DomainException('You have no credit.');
    }
}
```

### Extracting code result after lock release exception

Mutex implementations based on [`malkush\lock\mutex\LockMutex`][12] will throw
[`malkusch\lock\exception\LockReleaseException`][13] in case of lock release
problem, but the synchronized code block will be already executed at this point.
In order to read the code result (or an exception thrown there),
`LockReleaseException` provides methods to extract it.

Example:
```php
try {
    // or $mutex->check(...)
    $mutex->synchronized(function () {
        if (someCondition()) {
            throw new \DomainException();
        }

        return "result";
    });
} catch (LockReleaseException $unlock_exception) {
    if ($unlock_exception->getCodeException() !== null) {
        $code_exception = $unlock_exception->getCodeException()
        // do something with the code exception
    } else {
        $code_result = $unlock_exception->getCodeResult();
        // do something with the code result
    }
    
    // deal with LockReleaseException or propagate it
    throw $unlock_exception;
}
``` 

### Implementations

Because the [`malkusch\lock\mutex\Mutex`](#mutex) class is an abstract class,
you can choose from one of the provided implementations or create/extend your
own implementation.

- [`CASMutex`](#casmutex)
- [`FlockMutex`](#flockmutex)
- [`MemcachedMutex`](#memcachedmutex)
- [`PHPRedisMutex`](#phpredismutex)
- [`PredisMutex`](#predismutex)
- [`SemaphoreMutex`](#semaphoremutex)
- [`TransactionalMutex`](#transactionalmutex)
- [`MySQLMutex`](#mysqlmutex)
- [`PgAdvisoryLockMutex`](#pgadvisorylockmutex)

#### CASMutex

The **CASMutex** has to be used with a [Compare-and-swap][10] operation. This
mutex is lock free. It will repeat executing the code until the CAS operation
was successful. The code should therefore notify the mutex by calling
[`malkusch\lock\mutex\CASMutex::notify()`][11].

As the mutex keeps executing the critical code, it must not have any side
effects as long as the CAS operation was not successful.

Example:

```php
$mutex = new CASMutex();
$mutex->synchronized(function () use ($memcached, $mutex, $amount): void {
    $balance = $memcached->get("balance", null, $casToken);
    $balance -= $amount;
    if (!$memcached->cas($casToken, "balance", $balance)) {
        return;

    }
    $mutex->notify();
});
```

#### FlockMutex

The **FlockMutex** is a lock implementation based on [`flock()`](http://php.net/manual/en/function.flock.php).

Example:
```php
$mutex = new FlockMutex(fopen(__FILE__, "r"));
$mutex->synchronized(function () use ($bankAccount, $amount) {
    $balance = $bankAccount->getBalance();
    $balance -= $amount;
    if ($balance < 0) {
        throw new \DomainException("You have no credit.");

    }
    $bankAccount->setBalance($balance);
});
```

Timeouts are supported as an optional second argument. This uses the `ext-pcntl`
extension if possible or busy waiting if not.  

#### MemcachedMutex

The **MemcachedMutex**
is a spinlock implementation which uses the [`Memcached` API](http://php.net/manual/en/book.memcached.php).

Example:
```php
$memcache = new \Memcached();
$memcache->addServer("localhost", 11211);

$mutex = new MemcachedMutex("balance", $memcache);
$mutex->synchronized(function () use ($bankAccount, $amount) {
    $balance = $bankAccount->getBalance();
    $balance -= $amount;
    if ($balance < 0) {
        throw new \DomainException("You have no credit.");

    }
    $bankAccount->setBalance($balance);
});
```

#### PHPRedisMutex

The **PHPRedisMutex** is the distributed lock implementation of [RedLock](http://redis.io/topics/distlock)
which uses the [`phpredis` extension](https://github.com/phpredis/phpredis).

This implementation requires at least `phpredis-2.2.4`.

If used with a cluster of Redis servers, acquiring and releasing locks will continue to function as 
long as a majority of the servers still works.  

Example:
```php
$redis = new Redis();
$redis->connect("localhost");

$mutex = new PHPRedisMutex([$redis], "balance");
$mutex->synchronized(function () use ($bankAccount, $amount) {
    $balance = $bankAccount->getBalance();
    $balance -= $amount;
    if ($balance < 0) {
        throw new \DomainException("You have no credit.");

    }
    $bankAccount->setBalance($balance);
});
```

#### PredisMutex

The **PredisMutex** is the distributed lock implementation of [RedLock](http://redis.io/topics/distlock)
which uses the [`Predis` API](https://github.com/nrk/predis).

Example:
```php
$redis = new Client("redis://localhost");

$mutex = new PredisMutex([$redis], "balance");
$mutex->synchronized(function () use ($bankAccount, $amount) {
    $balance = $bankAccount->getBalance();
    $balance -= $amount;
    if ($balance < 0) {
        throw new \DomainException("You have no credit.");

    }
    $bankAccount->setBalance($balance);
});
```

#### SemaphoreMutex

The **SemaphoreMutex**
is a lock implementation based on [Semaphore](http://php.net/manual/en/ref.sem.php).

Example:
```php
$semaphore = sem_get(ftok(__FILE__, "a"));
$mutex     = new SemaphoreMutex($semaphore);
$mutex->synchronized(function () use ($bankAccount, $amount) {
    $balance = $bankAccount->getBalance();
    $balance -= $amount;
    if ($balance < 0) {
        throw new \DomainException("You have no credit.");

    }
    $bankAccount->setBalance($balance);
});
```

#### TransactionalMutex

The **TransactionalMutex**
delegates the serialization to the DBS. The exclusive code is executed within
a transaction. It's up to you to set the correct transaction isolation level.
However if the transaction fails (i.e. a `PDOException` was thrown), the code
will be executed again in a new transaction. Therefore the code must not have
any side effects besides SQL statements. Also the isolation level should be
conserved for the repeated transaction. If the code throws an exception,
the transaction is rolled back and not replayed again.

Example:
```php
$mutex = new TransactionalMutex($pdo);
$mutex->synchronized(function () use ($pdo, $accountId, $amount) {
    $select = $pdo->prepare("SELECT balance FROM account WHERE id = ? FOR UPDATE");
    $select->execute([$accountId]);
    $balance = $select->fetchColumn();

    $balance -= $amount;
    if ($balance < 0) {
        throw new \DomainException("You have no credit.");

    }
    $pdo->prepare("UPDATE account SET balance = ? WHERE id = ?")
        ->execute([$balance, $accountId]);
});
```

#### MySQLMutex

The **MySQLMutex** uses MySQL's 
[`GET_LOCK`](https://dev.mysql.com/doc/refman/8.0/en/miscellaneous-functions.html#function_get-lock)
function.

It supports time outs. If the connection to the database server is lost or interrupted, the lock is 
automatically released. 

Note that before MySQL 5.7.5 you cannot use nested locks, any new lock will silently release already
held locks. You should probably refrain from using this mutex on MySQL versions < 5.7.5.

```php
$pdo = new PDO("mysql:host=localhost;dbname=test", "username");

$mutex = new MySQLMutex($pdo, "balance", 15);
$mutex->synchronized(function () use ($bankAccount, $amount) {
    $balance = $bankAccount->getBalance();
    $balance -= $amount;
    if ($balance < 0) {
        throw new \DomainException("You have no credit.");

    }
    $bankAccount->setBalance($balance);
});
```

#### PgAdvisoryLockMutex

The **PgAdvisoryLockMutex** uses PostgreSQL's 
[advisory locking](https://www.postgresql.org/docs/9.4/static/functions-admin.html#FUNCTIONS-ADVISORY-LOCKS)
functions.

Named locks are offered. PostgreSQL locking functions require integers but the conversion is handled automatically.

No time outs are supported. If the connection to the database server is lost or interrupted, the lock is 
automatically released. 

```php
$pdo = new PDO("pgsql:host=localhost;dbname=test;", "username");

$mutex = new PgAdvisoryLockMutex($pdo, "balance");
$mutex->synchronized(function () use ($bankAccount, $amount) {
    $balance = $bankAccount->getBalance();
    $balance -= $amount;
    if ($balance < 0) {
        throw new \DomainException("You have no credit.");

    }
    $bankAccount->setBalance($balance);
});
```

## License and authors

This project is free and under the WTFPL.
Responsible for this project is Willem Stuursma-Ruwen <willem@stuursma.name>.

## Donations

If you like this project and feel generous donate a few Bitcoins here:
[1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA](bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA)

[1]: http://semver.org
[2]: https://github.com/nrk/predis
[3]: http://php.net/manual/en/book.pcntl.php
[4]: https://getcomposer.org
[5]: https://github.com/php-lock/lock/blob/master/classes/mutex/Mutex.php
[6]: https://github.com/php-lock/lock/blob/master/classes/mutex/Mutex.php#L38
[7]: https://github.com/php-lock/lock/blob/master/classes/mutex/Mutex.php#L57
[8]: https://github.com/php-lock/lock/blob/master/classes/util/DoubleCheckedLocking.php#L72
[9]: https://en.wikipedia.org/wiki/Double-checked_locking
[10]: https://en.wikipedia.org/wiki/Compare-and-swap
[11]: https://github.com/php-lock/lock/blob/master/classes/mutex/CASMutex.php#L44
[12]: https://github.com/php-lock/lock/blob/master/classes/mutex/LockMutex.php
[13]: https://github.com/php-lock/lock/blob/master/classes/exception/LockReleaseException.php