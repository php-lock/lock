<?php

declare(strict_types=1);

namespace malkusch\lock\Tests\util;

use malkusch\lock\util\LockUtil;
use PHPUnit\Framework\TestCase;

class LockUtilTest extends TestCase
{
    public function testGetInstance(): void
    {
        $instance = LockUtil::getInstance();

        self::assertSame(LockUtil::class, get_class($instance));
        self::assertSame($instance, LockUtil::getInstance());
    }

    public function testGetKeyPrefix(): void
    {
        self::assertSame('php-malkusch-lock', LockUtil::getInstance()->getKeyPrefix());
    }

    public function testMakeRandomToken(): void
    {
        $tokens = [];
        for ($i = 0; $i < 1_000; ++$i) {
            $token = LockUtil::getInstance()->makeRandomToken();
            self::assertMatchesRegularExpression('~^(?!0)[0-9a-f]{64}$~', $token);

            $tokens[] = $token;
        }

        self::assertSame($tokens, array_unique($tokens));
    }

    public function testMakeRandomTemporaryFilePath(): void
    {
        $pathPrefixRegex = preg_quote(sys_get_temp_dir() . \DIRECTORY_SEPARATOR . LockUtil::getInstance()->getKeyPrefix(), '~');

        $paths = [];
        for ($i = 0; $i < 1_000; ++$i) {
            $path = LockUtil::getInstance()->makeRandomTemporaryFilePath();
            self::assertMatchesRegularExpression('~^' . $pathPrefixRegex . '-\w{64}.txt$~', $path);

            $paths[] = $path;
        }

        self::assertSame($paths, array_unique($paths));

        self::assertMatchesRegularExpression('~^' . $pathPrefixRegex . '-foo-\w{64}.txt$~', LockUtil::getInstance()->makeRandomTemporaryFilePath('foo'));
        self::assertMatchesRegularExpression('~^' . $pathPrefixRegex . '-fo-o-\w{64}.txt$~', LockUtil::getInstance()->makeRandomTemporaryFilePath('fo/o'));
        self::assertMatchesRegularExpression('~^' . $pathPrefixRegex . '-fo-o-\w{64}.txt$~', LockUtil::getInstance()->makeRandomTemporaryFilePath('fo\o'));
        self::assertMatchesRegularExpression('~^' . $pathPrefixRegex . '-fo-o-\w{64}.txt$~', LockUtil::getInstance()->makeRandomTemporaryFilePath('fo:o'));
    }
}
