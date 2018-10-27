<?php

namespace malkusch\lock\exception;

/**
 * A Mutex exception.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1P5FAZ4QhXCuwYPnLZdk3PJsqePbu1UDDA Donations
 * @license WTFPL
 */
class MutexException extends \Exception
{

    const REDIS_NOT_ENOUGH_SERVERS = 1;
}
