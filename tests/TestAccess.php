<?php

declare(strict_types=1);

namespace Malkusch\Lock\Tests;

/**
 * Helper to access private/protected members for tests.
 *
 * @internal
 */
final class TestAccess
{
    private object $object;

    public function __construct(object $object)
    {
        $this->object = $object;
    }

    /**
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
     * @param string $method
     * @param array<int, mixed> $args
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
}
