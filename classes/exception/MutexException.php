<?php

namespace malkusch\lock\exception;

/**
 * A Mutex exception.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @link bitcoin:1335STSwu9hST4vcMRppEPgENMHD2r1REK Donations
 * @license WTFPL
 */
class MutexException extends \Exception
{

    const REDIS_NOT_ENOUGH_SERVERS = 1;
}
