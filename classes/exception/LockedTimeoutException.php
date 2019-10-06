<?php

namespace malkusch\lock\exception;

/**
 * A locked timeout was exceeded (time to wait while locked)
 */
class LockedTimeoutException extends TimeoutException
{
}
