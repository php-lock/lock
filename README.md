[![Build Status](https://travis-ci.org/php-lock/lock.svg?branch=master)](https://travis-ci.org/php-lock/lock)

This library helps executing critical code in concurrent situations.

# Installation

Use [Composer](https://getcomposer.org/):

```sh
composer require malkusch/lock
```

# Usage

The package is in the namespace
[`malkusch\lock`](http://malkusch.github.io/lock/api/namespace-malkusch.lock.html).

## Mutex

The
[`Mutex`](http://malkusch.github.io/lock/api/class-malkusch.lock.mutex.Mutex.html)
provides the API for this library.

### Mutex::synchronized()

[`Mutex::synchronized()`](http://malkusch.github.io/lock/api/class-malkusch.lock.mutex.Mutex.html#_synchronized)
executes code exclusively. This method guarantees that the code is only executed
by one process at once. Other processes have to wait until the mutex is available.
The critical code may throw an exception, which would release the lock as well.

Example:
```php
$mutex->synchronized(function () use ($bankAccount, $amount) {
    $balance = $bankAccount->getBalance();
    $balance -= $amount;
    if ($balance < 0) {
        throw new \DomainException("You have no credit.");

    }
    $bankAccount->setBalance($balance);
});
```

### Mutex::check()

[`Mutex::check()`](http://malkusch.github.io/lock/api/class-malkusch.lock.mutex.Mutex.html#_check)
performs a double-checked locking pattern. I.e. if the check fails, no lock
will be acquired. Else if the check was true, a lock will be acquired and the
check will be perfomed as well together with the critical code.

Example:
```php
$mutex->check(function () use ($bankAccount, $amount) {
    return $bankAccount->getBalance() >= $amount;

})->then(function () use ($bankAccount, $amount) {
    $balance = $bankAccount->getBalance();
    $balance -= $amount;
    $bankAccount->setBalance($balance);
});
```

### Implementations

The `Mutex` is an abstract class. You will have to choose an implementation:

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

The **CASMutex**
has to be used with a [Compare-and-swap](https://en.wikipedia.org/wiki/Compare-and-swap) operation.
This mutex is lock free. It will repeat executing the code until the CAS operation was
successful. The code should therefore notify the mutex by calling
[`CASMutex::notify()`](http://malkusch.github.io/lock/api/class-malkusch.lock.mutex.CASMutex.html#_notify).

As the mutex keeps executing the critical code, it must not have any side effects
as long as the CAS operation was not successful.

Example:
```php
$mutex = new CASMutex();
$mutex->synchronized(function () use ($memcached, $mutex, $amount) {
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

Timeouts are supported as an optional second argument. This uses the pcntl extension if 
possible or busy waiting if not.  

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



# License and authors

This project is free and under the WTFPL.
Responsible for this project is Willem Stuursma-Ruwen <willem@stuursma.name>.

## Donations

If you like this project and feel generous donate a few Bitcoins here:
[1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA](bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA)


