<?php

declare(strict_types=1);

namespace malkusch\lock\exception;

/**
 * Deadline exception.
 */
class DeadlineException extends \RuntimeException implements PhpLockException {}
