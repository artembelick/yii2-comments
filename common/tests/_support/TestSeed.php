<?php

declare(strict_types=1);

namespace common\tests\_support;

use Codeception\Test\Cest;
use Codeception\Test\TestCaseWrapper;
use Codeception\TestInterface;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionMethod;

/**
 * Seed Faker из ID теста (ДТ-2): повторный прогон того же теста даёт те же данные.
 * Хэш FNV-1a по байтам UTF-8 от ID, как в e2e/support/faker.ts. ID ищется в TestDox или docblock метода,
 * а если его нет — берётся имя метода. К ID добавляются имя метода и имя набора из провайдера: иначе
 * тесты с общим ID и наборы одного теста получили бы одни и те же метки.
 */
final class TestSeed
{
    private const int FNV_OFFSET_BASIS = 2_166_136_261;

    private const int FNV_PRIME = 16_777_619;

    private const int UINT32 = 0xFFFFFFFF;

    private const string TEST_ID = '/(?:UNIT|REG|SMOKE|E2E)(?:-[A-Z0-9]+)+[a-z]?/u';

    public static function of(TestInterface $test): int
    {
        $method = self::methodOf($test);
        $title = $method instanceof ReflectionMethod ? self::titleOf($method) : $test->getMetadata()->getName();

        $name = $method instanceof ReflectionMethod ? ' ' . $method->getName() : '';

        return self::hash(self::idOf($title) . $name . self::dataSetOf($test));
    }

    private static function idOf(string $title): string
    {
        return preg_match(self::TEST_ID, $title, $match) === 1 ? $match[0] : $title;
    }

    private static function dataSetOf(TestInterface $test): string
    {
        if ($test instanceof Cest) {
            $example = $test->getMetadata()->getCurrent('example');

            // Невалидный UTF-8 в наборе (REG-G-18) заменяется на U+FFFD, а не роняет тест.
            return $example === null ? '' : ' ' . json_encode($example, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        if ($test instanceof TestCaseWrapper) {
            $dataName = $test->getTestCase()->dataName();

            return $dataName === '' ? '' : ' ' . $dataName;
        }

        return '';
    }

    private static function hash(string $source): int
    {
        $hash = self::FNV_OFFSET_BASIS;
        foreach (str_split($source) as $byte) {
            $hash = (($hash ^ ord($byte)) * self::FNV_PRIME) & self::UINT32;
        }

        return $hash;
    }

    private static function methodOf(TestInterface $test): ?ReflectionMethod
    {
        if ($test instanceof Cest) {
            return new ReflectionMethod($test->getTestInstance(), $test->getTestMethod());
        }

        if ($test instanceof TestCaseWrapper) {
            $testCase = $test->getTestCase();

            return new ReflectionMethod($testCase, $testCase->name());
        }

        return null;
    }

    private static function titleOf(ReflectionMethod $method): string
    {
        $testDox = $method->getAttributes(TestDox::class)[0] ?? null;

        return $testDox?->newInstance()->text() ?? $method->getDocComment() . $method->getName();
    }
}
