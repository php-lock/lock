<?php

declare(strict_types=1);

namespace malkusch\lock\exception;

/**
 * Common php-lock/lock exception interface.
 *
 * @method string                     getMessage()
 * @method int                        getCode()
 * @method string                     getFile()
 * @method int                        getLine()
 * @method list<array<string, mixed>> getTrace()
 * @method string                     getTraceAsString()
 * @method \Throwable|null            getPrevious()
 * @method string                     __toString()
 */
interface PhpLockException {}
