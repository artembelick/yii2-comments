<?php

declare(strict_types=1);

namespace common\tests\unit\text;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\text\CommentTextNormalizer;
use PHPUnit\Framework\Attributes\TestDox;

final class CommentTextNormalizerTest extends Unit
{
    #[TestDox('UNIT-NORM-01: normalizeName обрезает по краям ASCII- и Unicode-пробелы и символы нулевой ширины')]
    #[DataProvider('paddedNames')]
    public function testNormalizeNameTrimsEdges(string $raw, string $expected): void
    {
        $normalizer = new CommentTextNormalizer();

        $name = $normalizer->normalizeName($raw);

        self::assertSame($expected, $name);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function paddedNames(): array
    {
        return [
            'ASCII-пробелы' => ['  Ян  ', 'Ян'],
            'NBSP' => ["\u{00A0}Ян\u{00A0}", 'Ян'],
            'идеографический пробел U+3000' => ["\u{3000}Ян\u{3000}", 'Ян'],
            'нулевая ширина U+200B…200D и U+FEFF' => ["\u{200B}\u{200C}Ян\u{200D}\u{FEFF}", 'Ян'],
            'внутреннее содержимое не меняется' => [" Анна\u{00A0}\u{200B} Мария ", "Анна\u{00A0}\u{200B} Мария"],
        ];
    }

    #[TestDox('UNIT-NORM-02: имя из одних пробелов и символов нулевой ширины → пустая строка')]
    public function testBlankNameBecomesEmpty(): void
    {
        $normalizer = new CommentTextNormalizer();

        $name = $normalizer->normalizeName(" \u{00A0}\u{200B}\u{3000}\u{FEFF} ");

        self::assertSame('', $name);
    }

    #[TestDox('UNIT-NORM-03: normalizeText заменяет \\r\\n и одиночный \\r на \\n')]
    public function testNormalizeTextUnifiesLineBreaks(): void
    {
        $normalizer = new CommentTextNormalizer();

        $text = $normalizer->normalizeText("первая\r\nвторая\rтретья\nчетвёртая");

        self::assertSame("первая\nвторая\nтретья\nчетвёртая", $text);
    }

    #[TestDox('UNIT-NORM-04: normalizeText обрезает по краям пробелы, нулевую ширину и переносы, внутренние сохраняет')]
    public function testNormalizeTextTrimsEdgesKeepingInnerBreaks(): void
    {
        $normalizer = new CommentTextNormalizer();

        $text = $normalizer->normalizeText("\r\n \u{00A0}\u{200B}\nпервая  строка\n\n  вторая\u{FEFF}\n \u{3000}\r\n");

        self::assertSame("первая  строка\n\n  вторая", $text);
    }

    #[TestDox('UNIT-NORM-05: невалидный UTF-8 возвращается без изменений')]
    public function testInvalidUtf8IsReturnedAsIs(): void
    {
        $normalizer = new CommentTextNormalizer();
        $invalidUtf8 = " \xC3\x28 ";

        $normalized = [$normalizer->normalizeName($invalidUtf8), $normalizer->normalizeText($invalidUtf8)];

        self::assertSame([$invalidUtf8, $invalidUtf8], $normalized);
    }
}
