<?php

declare(strict_types=1);

namespace common\tests\unit\text;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\text\CommentTextNormalizer;
use Override;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

final class CommentTextNormalizerTest extends Unit
{
    /** UNIT-NORM-10 занижает лимит PCRE: он глобальный для процесса и возвращается после каждого теста (ИЗ-4). */
    #[Override]
    protected function _after(): void
    {
        ini_restore('pcre.backtrack_limit');
    }

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

    #[TestDox('UNIT-NORM-05: битые байты UTF-8 вырезаются из имени и текста')]
    public function testInvalidUtf8BytesAreStripped(): void
    {
        $normalizer = new CommentTextNormalizer();
        $withBrokenBytes = " Ян\xC3\x28\xFFМария\xE2\x82 ";

        $normalized = [$normalizer->normalizeName($withBrokenBytes), $normalizer->normalizeText($withBrokenBytes)];

        self::assertSame(['Ян(Мария', 'Ян(Мария'], $normalized);
    }

    #[TestDox('UNIT-NORM-06: управляющие символы категории Cc вырезаются из имени и текста, \\n в тексте остаётся')]
    #[DataProvider('controlCharacters')]
    public function testControlCharactersAreStripped(string $character): void
    {
        $normalizer = new CommentTextNormalizer();

        $normalized = [
            $normalizer->normalizeName('Ян' . $character . 'Мария'),
            $normalizer->normalizeText("первая\n" . $character . 'вторая'),
        ];

        self::assertSame(['ЯнМария', "первая\nвторая"], $normalized);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function controlCharacters(): array
    {
        return [
            'NUL' => ["\0"],
            'BEL \\x07' => ["\x07"],
            'VT \\x0B' => ["\x0B"],
            'DEL \\x7F' => ["\x7F"],
            'NEL U+0085' => ["\u{0085}"],
        ];
    }

    #[TestDox('UNIT-NORM-07: табуляция и переносы в имени → пробел, внутренние пробелы не схлопываются')]
    #[DataProvider('nameBreaks')]
    public function testNameBreaksBecomeSpaces(string $raw, string $expected): void
    {
        $normalizer = new CommentTextNormalizer();

        $name = $normalizer->normalizeName($raw);

        self::assertSame($expected, $name);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nameBreaks(): array
    {
        return [
            'две табуляции → два пробела' => ["Ян\t\tМария", 'Ян  Мария'],
            '\\n' => ["Ян\nМария", 'Ян Мария'],
            '\\r\\n → один пробел' => ["Ян\r\nМария", 'Ян Мария'],
            '\\r' => ["Ян\rМария", 'Ян Мария'],
            'U+2028' => ["Ян\u{2028}Мария", 'Ян Мария'],
            'U+2029' => ["Ян\u{2029}Мария", 'Ян Мария'],
            '\\r\\0\\n → два пробела: перенос распознаётся до вырезания' => ["Ян\r\0\nМария", 'Ян  Мария'],
        ];
    }

    #[TestDox('UNIT-NORM-07: табуляция в тексте → пробел, U+2028 и U+2029 → \\n')]
    #[DataProvider('textBreaks')]
    public function testTextTabsAndSeparatorsAreReplaced(string $raw, string $expected): void
    {
        $normalizer = new CommentTextNormalizer();

        $text = $normalizer->normalizeText($raw);

        self::assertSame($expected, $text);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function textBreaks(): array
    {
        return [
            'две табуляции → два пробела' => ["первая\t\tстрока", 'первая  строка'],
            'U+2028' => ["первая\u{2028}вторая", "первая\nвторая"],
            'U+2029' => ["первая\u{2029}вторая", "первая\nвторая"],
            '\\r\\0\\n → два переноса: перенос распознаётся до вырезания' => ["первая\r\0\nвторая", "первая\n\nвторая"],
        ];
    }

    #[TestDox('UNIT-NORM-08: символы и марки управления направлением текста вырезаются из имени и текста')]
    #[DataProvider('bidiCharacters')]
    public function testBidiControlsAreStripped(string $character): void
    {
        $normalizer = new CommentTextNormalizer();

        $normalized = [
            $normalizer->normalizeName('Ян' . $character . 'Мария'),
            $normalizer->normalizeText('первая' . $character . 'вторая'),
        ];

        self::assertSame(['ЯнМария', 'перваявторая'], $normalized);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bidiCharacters(): array
    {
        return [
            'LRE U+202A' => ["\u{202A}"],
            'RLE U+202B' => ["\u{202B}"],
            'PDF U+202C' => ["\u{202C}"],
            'LRO U+202D' => ["\u{202D}"],
            'RLO U+202E' => ["\u{202E}"],
            'LRI U+2066' => ["\u{2066}"],
            'RLI U+2067' => ["\u{2067}"],
            'FSI U+2068' => ["\u{2068}"],
            'PDI U+2069' => ["\u{2069}"],
            'LRM U+200E' => ["\u{200E}"],
            'RLM U+200F' => ["\u{200F}"],
            'ALM U+061C' => ["\u{061C}"],
        ];
    }

    #[TestDox('UNIT-NORM-09: ZWJ, ZWNJ и VS16 внутри остаются: эмодзи «семья» не ломается')]
    #[DataProvider('joiners')]
    public function testJoinersAreKept(string $value): void
    {
        $normalizer = new CommentTextNormalizer();

        $normalized = [$normalizer->normalizeName($value), $normalizer->normalizeText($value)];

        self::assertSame([$value, $value], $normalized);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function joiners(): array
    {
        return [
            'ZWJ U+200D' => ["Ян\u{200D}Мария"],
            'ZWNJ U+200C' => ["Ян\u{200C}Мария"],
            'VS16 U+FE0F' => ["Ян \u{2764}\u{FE0F}"],
            'эмодзи «семья»' => ["\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"],
        ];
    }

    #[TestDox('UNIT-NORM-09: эмодзи «семья» после нормализации остаётся 5 символами')]
    public function testFamilyEmojiStaysFiveCharacters(): void
    {
        $normalizer = new CommentTextNormalizer();
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";

        $name = $normalizer->normalizeName($family);

        self::assertSame(5, mb_strlen($name));
    }

    #[TestDox('UNIT-NORM-10: сбой PCRE → исключение, а не пустая строка')]
    public function testRegexFailureThrows(): void
    {
        $normalizer = new CommentTextNormalizer();
        ini_set('pcre.backtrack_limit', '1000');
        $manyInnerSpaces = 'Ян' . str_repeat(' ', 5000) . 'Мария';

        $this->expectException(RuntimeException::class);

        $normalizer->normalizeName($manyInnerSpaces);
    }
}
