<?php
/**
 * TestAccess - Helper for accessing private/protected properties and methods in tests.
 */
class TestAccess {
    private $object;

    public function __construct($object) {
        $this->object = $object;
    }

    public function getProperty($property) {
        $ref = new \ReflectionProperty($this->object, $property);
        if (PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        return $ref->getValue($this->object);
    }

    public function setProperty($property, $value) {
        $ref = new \ReflectionProperty($this->object, $property);
        if (PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $ref->setValue($this->object, $value);
    }

    public function callMethod($method, ...$args) {
        $ref = new \ReflectionMethod($this->object, $method);
        if (PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        return $ref->invokeArgs($this->object, $args);
    }
}