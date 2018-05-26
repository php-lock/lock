<?php

namespace malkusch\lock\mutex;

use Predis\Client;
use Redis;
use ezcSystemInfo;
use Spork\ProcessManager;

/**
 * Concurrency Tests for Mutex.
 *
 * If you want to run integration tests you should provide these environment variables:
 *
 * - MEMCACHE_HOST
 * - REDIS_URIS - a comma separated list of redis:// URIs.
 * - MYSQL_DSN, MYSQL_USER
 * - PGSQL_DSN, PGSQL_USER
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 * @requires PHP
 * @see Mutex
 */
class MutexConcurrencyTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \PDO The pdo instance.
     */
    private $pdo;

    /**
     * @var string
     */
    private $path;

    protected function tearDown()
    {
        if ($this->path) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    /**
     * Gets a PDO instance.
     *
     * @param string $dsn The DSN.
     * @param string $user The user.
     *
     * @return \PDO The PDO.
     */
    private function getPDO($dsn, $user)
    {
        if (is_null($this->pdo)) {
            $this->pdo = new \PDO($dsn, $user);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        return $this->pdo;
    }
    
    /**
     * Forks, runs code in the children and wait until all finished.
     *
     * @param int $concurrency The amount of forks.
     * @param callable $code The code for the fork.
     */
    private function fork($concurrency, callable $code)
    {
        $manager = new ProcessManager();
        $manager->setDebug(true);

        for ($i = 0; $i < $concurrency; $i++) {
            $manager->fork($code);
        }

        $manager->check();
    }
    
    /**
     * Tests high contention empirically.
     *
     * @param callable $code         The counter code.
     * @param callable $mutexFactory The mutex factory.
     *
     * @test
     * @dataProvider provideTestHighContention
     */
    public function testHighContention(callable $code, callable $mutexFactory)
    {
        $concurrency = max(2, ezcSystemInfo::getInstance()->cpuCount);
        $iterations  = 20000 / $concurrency;
        $timeout = $concurrency * 20;
        
        $this->fork($concurrency, function () use ($mutexFactory, $timeout, $iterations, $code) {
            $mutex = call_user_func($mutexFactory, $timeout);
            for ($i = 0; $i < $iterations; $i++) {
                $mutex->synchronized(function () use ($code) {
                    call_user_func($code, 1);
                });
            }
        });

        $counter = call_user_func($code, 0);
        $this->assertEquals($concurrency * $iterations, $counter);
    }
    
    /**
     * Returns test cases for testHighContention().
     *
     * @return array The test cases.
     */
    public function provideTestHighContention()
    {
        $cases = array_map(function (array $mutexFactory) {
            $file = tmpfile();
            $this->assertEquals(4, fwrite($file, pack("i", 0)));

            return [
                function ($increment) use ($file) {
                    rewind($file);
                    flock($file, LOCK_EX);
                    $data = fread($file, 4);

                    $this->assertEquals(4, strlen($data));

                    $counter = unpack("i", $data)[1];

                    $counter += $increment;
                    
                    rewind($file);
                    fwrite($file, pack("i", $counter));

                    flock($file, LOCK_UN);

                    return $counter;
                },
                $mutexFactory[0]
            ];
        }, $this->provideMutexFactories());
        
        $addPDO = function ($dsn, $user, $vendor) use (&$cases) {
            $pdo = $this->getPDO($dsn, $user);

            $options = ["mysql" => "engine=InnoDB"];
            $option  = isset($options[$vendor]) ? $options[$vendor] : "";
            $pdo->exec("CREATE TABLE IF NOT EXISTS counter(id INT PRIMARY KEY, counter INT) $option");

            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM counter");
            $pdo->exec("INSERT INTO counter VALUES (1, 0)");
            $pdo->commit();

            $this->pdo = null;

            $cases[$vendor] = [
                function ($increment) use ($dsn, $user) {
                    // This prevents using a closed connection from a child.
                    if ($increment == 0) {
                        $this->pdo = null;
                    }
                    $pdo = $this->getPDO($dsn, $user);
                    $id  = 1;
                    $select = $pdo->prepare("SELECT counter FROM counter WHERE id = ? FOR UPDATE");
                    $select->execute([$id]);
                    $counter = $select->fetchColumn();

                    $counter += $increment;

                    $pdo->prepare("UPDATE counter SET counter = ? WHERE id = ?")
                        ->execute([$counter, $id]);

                    return $counter;
                },
                function ($timeout = 3) use ($dsn, $user) {
                    $this->pdo = null;
                    $pdo = $this->getPDO($dsn, $user);
                    return new TransactionalMutex($pdo, $timeout);
                }
            ];
        };
        
        if (getenv("MYSQL_DSN")) {
            $dsn  = getenv("MYSQL_DSN");
            $user = getenv("MYSQL_USER");
            $addPDO($dsn, $user, "mysql");
        }
        
        if (getenv("PGSQL_DSN")) {
            $dsn  = getenv("PGSQL_DSN");
            $user = getenv("PGSQL_USER");
            $addPDO($dsn, $user, "postgres");
        }
        
        return $cases;
    }
    
    /**
     * Tests that two processes run sequentially.
     *
     * @param callable $mutexFactory The Mutex factory.
     * @test
     * @dataProvider provideMutexFactories
     */
    public function testSerialisation(callable $mutexFactory)
    {
        $timestamp = microtime(true);
        
        $this->fork(2, function () use ($mutexFactory) {
            $mutex = call_user_func($mutexFactory);
            $mutex->synchronized(function () {
                usleep(500000);
            });
        });

        $delta = microtime(true) - $timestamp;
        $this->assertGreaterThan(1, $delta);
    }
    
    /**
     * Provides Mutex factories.
     *
     * @return callable[][] The mutex factories.
     */
    public function provideMutexFactories()
    {
        $this->path = tempnam(sys_get_temp_dir(), "mutex-concurrency-test");

        $cases = [
            "flock" => [function ($timeout = 3) {
                $file = fopen($this->path, "w");
                return new FlockMutex($file);
            }],
                    
            "semaphore" => [function ($timeout = 3) {
                $semaphore = sem_get(ftok($this->path, "b"));
                $this->assertTrue(is_resource($semaphore));
                return new SemaphoreMutex($semaphore);
            }],
        ];
            
        if (getenv("MEMCACHE_HOST")) {
            $cases["memcached"] = [function ($timeout = 3) {
                $memcached = new \Memcached();
                $memcached->addServer(getenv("MEMCACHE_HOST"), 11211);
                return new MemcachedMutex("test", $memcached, $timeout);
            }];
        }
        
        if (getenv("REDIS_URIS") && PHP_VERSION >= "7") {
            $uris = explode(",", getenv("REDIS_URIS"));

            $cases["PredisMutex"] = [function ($timeout = 3) use ($uris) {
                $clients = array_map(
                    function ($uri) {
                        return new Client($uri);
                    },
                    $uris
                );
                return new PredisMutex($clients, "test", $timeout);
            }];

            $cases["PHPRedisMutex"] = [function ($timeout = 3) use ($uris) {
                $apis = array_map(
                    function ($uri) {
                        $redis = new Redis();
                        
                        $uri = parse_url($uri);
                        if (!empty($uri["port"])) {
                            $redis->connect($uri["host"], $uri["port"]);
                        } else {
                            $redis->connect($uri["host"]);
                        }
                        
                        return $redis;
                    },
                    $uris
                );
                return new PHPRedisMutex($apis, "test", $timeout);
            }];
        }

        if (getenv("MYSQL_DSN")) {
            $cases["MySQLMutex"] = [function ($timeout = 3) {
                $pdo = new \PDO(getenv("MYSQL_DSN"), getenv("MYSQL_USER"));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new MySQLMutex($pdo, "test", $timeout);
            }];
        }

        if (getenv("PGSQL_DSN")) {
            $cases["PgAdvisoryLockMutex"] = [function () {
                $pdo = new \PDO(getenv("PGSQL_DSN"), getenv("PGSQL_USER"));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                return new PgAdvisoryLockMutex($pdo, "test" . time());
            }];
        }
        
        return $cases;
    }
}
