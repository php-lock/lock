<?php

namespace malkusch\lock\mutex;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;

/**
 * @author Dmitrii Korotovskii <dmitry@korotovsky.io>
 * @license WTFPL
 */
class PgAdvisoryMutex extends LockMutex
{
    /**
     * @var \PDO $pdo The PDO.
     */
    private $pdo;

    /**
     * @var array The resource map <String, Integer>
     */
    private $map;

    /**
     * @var string
     */
    private $resourceType;

    /**
     * @var int
     */
    private $resourceId;

    /**
     * PgAdvisoryMutex constructor.
     * @param \PDO    $pdo           The PDO connection
     * @param array   $map           Resource map
     * @param string  $resourceType  Name of the current lock key
     * @param int     $resourceId    Resource id
     */
    public function __construct(\PDO $pdo, array $map, $resourceType, $resourceId = 0)
    {
        if (!array_key_exists($resourceType, $map)) {
            throw new \RuntimeException(sprintf('The key "%s" is not found in the map', $name));
        }

        $this->pdo = $pdo;
        $this->map = $map;

        $this->resourceType = $resourceType;
        $this->resourceId = $resourceId;
    }

    /**
     * Acquires the lock.
     *
     * This method blocks until the lock was acquired.
     *
     * @throws LockAcquireException The lock could not be acquired.
     */
    protected function lock()
    {
        $isLocked = $this->executeFunction('pg_try_advisory_lock');
        if ($isLocked === false) {
            throw new LockAcquireException(sprintf('Failed to acquire advisory lock on resource "%s" with identifier "%s".', $this->resourceType, $this->resourceId));
        }
    }

    /**
     * Releases the lock.
     *
     * @throws LockReleaseException The lock could not be released.
     */
    protected function unlock()
    {
        $isUnLocked = $this->executeFunction('pg_advisory_unlock');
        if ($isUnLocked === false) {
            throw new LockReleaseException(sprintf('Failed to release advisory lock on resource "%s" with identifier "%s".', $this->resourceType, $this->resourceId));
        }
    }

    /**
     * @param string $fnName
     *
     * @return bool
     */
    private function executeFunction($fnName)
    {
        $arguments = [
            (int) $this->map[$this->resourceType],
            (int) $this->resourceId,
        ];

        $stmt = $this->pdo->prepare("SELECT $fnName(?, ?) AS lock");
        $stmt->execute($arguments);
        $stmt->setFetchMode(\PDO::FETCH_ASSOC);

        $row = $stmt->fetch();

        return (bool) $row['lock'];
    }
}
