<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests;

use Closure;

/**
 * Helper to access private/protected members for tests.
 *
 * @internal
 */
final class TestAccess
{
    private object $object;
    private ?float $acquireTimeout = null;

    public function __construct(object $object)
    {
        $this->object = $object;
    }

    /**
     * Gets a private/protected property on the wrapped object.
     * 
     * @param string $property
     * @return mixed
     */
    public function getProperty(string $property): mixed
    {
        $accessor = \Closure::bind(
            function (string $property) {
                /** @phpstan-ignore-next-line */
                return $this->$property;
            },
            $this->object,
            $this->object
        );

        return $accessor($property);
    }

    /**
     * Sets a private/protected property on the wrapped object.
     * 
     * @param string $property
     * @param mixed $value
     */
    public function setProperty(string $property, mixed $value): void
    {
        $accessor = \Closure::bind(
            function (string $property, mixed $value): void {
                /** @phpstan-ignore-next-line */
                $this->$property = $value;
            },
            $this->object,
            $this->object
        );

        $accessor($property, $value);
    }

    /**
     * Proxy calls to inaccessible methods on the wrapped object.
     * 
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public function callMethod(string $method, array $args = []): mixed
    {
        $caller = \Closure::bind(
            function (string $method, array $args): mixed {
                /** @phpstan-ignore-next-line */
                return $this->$method(...$args);
            },
            $this->object,
            $this->object
        );

        return $caller($method, $args);
    }

    /**
     * Proxy calls to inaccessible methods on the wrapped object.
     *
     * @param array<int, mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->callMethod($method, $args);
    }

    /**
     * Proxy access to inaccessible properties on the wrapped object.
     * 
     * @param string $property
     */
    public function __get(string $property): mixed
    {
        return $this->getProperty($property);
    }

    /**
     * Proxy setting of inaccessible properties on the wrapped object.
     * 
     * @param string $property
     * @param mixed $value 
     */
    public function __set(string $property, mixed $value): void
    {
        $this->setProperty($property, $value);
    }
}
