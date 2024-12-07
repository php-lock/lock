<?php

declare(strict_types=1);

namespace Malkusch\Lock\Util;

/**
 * @internal
 */
class LockUtil
{
    private static ?self $instance = null;

    private function __construct() {}

    final public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @return non-falsy-string
     */
    final public function getKeyPrefix(): string
    {
        return 'php-malkusch-lock';
    }

    /**
     * @return non-falsy-string
     */
    public function makeRandomToken(): string
    {
        do {
            $randomBytes = random_bytes(16);
            $token = bin2hex($randomBytes) . md5($randomBytes . microtime());
        } while (str_starts_with($token, '0'));

        return $token;
    }

    /**
     * @return non-falsy-string
     */
    public function makeRandomTemporaryFilePath(string $name = ''): string
    {
        return sys_get_temp_dir() . \DIRECTORY_SEPARATOR
            . $this->getKeyPrefix() . '-'
            . ($name !== '' ? str_replace(['/', '\\', ':'], '-', $name) . '-' : '')
            . $this->makeRandomToken() . '.txt';
    }

    /**
     * @return non-empty-string
     */
    public function formatTimeout(float $value): string
    {
        $res = (string) round($value, 6);
        if (\is_finite($value) && strpos($res, '.') === false) {
            $res .= '.0';
        }

        return $res;
    }
}
