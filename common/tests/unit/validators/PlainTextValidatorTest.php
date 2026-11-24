<?php

declare(strict_types=1);

namespace common\tests\unit\validators;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\validators\PlainTextValidator;
use PHPUnit\Framework\Attributes\TestDox;
use Yii;
use yii\base\DynamicModel;
use yii\base\InvalidConfigException;

final class PlainTextValidatorTest extends Unit
{
    #[TestDox('UNIT-VAL-01: имя 2–64 символа проходит, длина в символах Unicode')]
    #[DataProvider('namesOfValidLength')]
    public function testNameOfValidLengthPasses(string $name): void
    {
        $validator = $this->nameValidator();

        $valid = $validator->validate($name, $error);

        self::assertSame([true, null], [$valid, $error]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function namesOfValidLength(): array
    {
        return [
            '2 символа' => ['Ян'],
            '64 символа кириллицы' => [str_repeat('ж', 64)],
        ];
    }

    #[TestDox('UNIT-VAL-01: имя короче 2 или длиннее 64 символов отклоняется')]
    #[DataProvider('namesOfInvalidLength')]
    public function testNameOfInvalidLengthIsRejected(string $name, string $expectedKey): void
    {
        $validator = $this->nameValidator();

        $validator->validate($name, $error);

        self::assertSame(Yii::t('app', $expectedKey), $error);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function namesOfInvalidLength(): array
    {
        return [
            '1 символ → name.too_short' => ['Я', 'name.too_short'],
            '65 символов → name.too_long' => [str_repeat('ж', 65), 'name.too_long'],
        ];
    }

    #[TestDox('UNIT-VAL-02: текст 1–2000 символов проходит')]
    #[DataProvider('textsOfValidLength')]
    public function testTextOfValidLengthPasses(string $text): void
    {
        $validator = $this->textValidator();

        $valid = $validator->validate($text, $error);

        self::assertSame([true, null], [$valid, $error]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function textsOfValidLength(): array
    {
        return [
            '1 символ' => ['a'],
            '2000 символов' => [str_repeat('ж', 2000)],
        ];
    }

    #[TestDox('UNIT-VAL-02: текст 2001 символ → text.too_long')]
    public function testTooLongTextIsRejected(): void
    {
        $validator = $this->textValidator();

        $validator->validate(str_repeat('ж', 2001), $error);

        self::assertSame(Yii::t('app', 'text.too_long'), $error);
    }

    #[TestDox('UNIT-VAL-03: управляющие символы категории Cc → invalid_chars')]
    #[DataProvider('controlCharacters')]
    public function testControlCharactersAreRejected(string $character): void
    {
        $validator = $this->textValidator();

        $validator->validate('до' . $character . 'после', $error);

        self::assertSame(Yii::t('app', 'text.invalid_chars'), $error);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function controlCharacters(): array
    {
        return [
            'NUL' => ["\0"],
            'BEL \\x07' => ["\x07"],
            'DEL \\x7F' => ["\x7F"],
            'NEL U+0085' => ["\u{0085}"],
            'табуляция' => ["\t"],
        ];
    }

    #[TestDox('UNIT-VAL-04: перенос строки при multiline=false → name.multiline')]
    public function testLineBreakInSingleLineFieldIsRejected(): void
    {
        $validator = $this->nameValidator();

        $validator->validate("Ян\nМария", $error);

        self::assertSame(Yii::t('app', 'name.multiline'), $error);
    }

    #[TestDox('UNIT-VAL-04: перенос строки при multiline=true проходит')]
    public function testLineBreakInMultilineFieldPasses(): void
    {
        $validator = $this->textValidator();

        $valid = $validator->validate("первая\nвторая", $error);

        self::assertSame([true, null], [$valid, $error]);
    }

    #[TestDox('UNIT-VAL-05: символы управления направлением текста → invalid_chars')]
    #[DataProvider('bidiCharacters')]
    public function testBidiControlsAreRejected(string $character): void
    {
        $validator = $this->nameValidator();

        $validator->validate('Ян' . $character . 'Мария', $error);

        self::assertSame(Yii::t('app', 'name.invalid_chars'), $error);
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
        ];
    }

    #[TestDox('UNIT-VAL-06: ZWJ, ZWNJ и VS16 разрешены')]
    #[DataProvider('joiners')]
    public function testJoinersPass(string $name): void
    {
        $validator = $this->nameValidator();

        $valid = $validator->validate($name, $error);

        self::assertSame([true, null], [$valid, $error]);
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
        ];
    }

    #[TestDox('UNIT-VAL-06: эмодзи «семья» считается как 5 символов')]
    public function testFamilyEmojiCountsAsFiveCharacters(): void
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $validator = new PlainTextValidator(['min' => 5, 'max' => 5, 'multiline' => false, 'messagePrefix' => 'name']);

        $valid = $validator->validate($family, $error);

        self::assertSame([true, null], [$valid, $error]);
    }

    #[TestDox('UNIT-VAL-07: невалидный UTF-8 → invalid_chars')]
    public function testInvalidUtf8IsRejected(): void
    {
        $validator = $this->textValidator();

        $validator->validate("Ян\xC3\x28", $error);

        self::assertSame(Yii::t('app', 'text.invalid_chars'), $error);
    }

    #[TestDox('UNIT-VAL-08: значение не строкой → field.invalid')]
    #[DataProvider('nonStrings')]
    public function testNonStringIsRejected(mixed $value): void
    {
        $validator = $this->nameValidator();

        $validator->validate($value, $error);

        self::assertSame(Yii::t('app', 'field.invalid'), $error);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonStrings(): array
    {
        return [
            'массив' => [['Ян']],
            'число' => [42],
            'null' => [null],
        ];
    }

    #[TestDox('UNIT-VAL-09: на поле одна ошибка, первая по порядку: тип → обязательность → символы → многострочность → длина')]
    #[DataProvider('severalViolations')]
    public function testOnlyFirstViolationIsReported(mixed $name, string $expectedKey): void
    {
        $model = new DynamicModel();
        $model->defineAttribute('name', $name);

        $this->nameValidator()->validateAttribute($model, 'name');

        self::assertSame([Yii::t('app', $expectedKey)], $model->getErrors('name'));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function severalViolations(): array
    {
        $tooLong = str_repeat('ж', 65);

        return [
            'массив длинных строк → field.invalid' => [[$tooLong], 'field.invalid'],
            'пустая строка → name.required, а не name.too_short' => ['', 'name.required'],
            'табуляция, перенос и длина → name.invalid_chars' => ["\t\n" . $tooLong, 'name.invalid_chars'],
            'перенос и длина → name.multiline' => ["\n" . $tooLong, 'name.multiline'],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[TestDox('UNIT-VAL-10: без min или max валидатор не создаётся: умолчание длины отклоняло бы весь ввод')]
    #[DataProvider('incompleteConfigs')]
    public function testLengthBoundsAreRequired(array $config): void
    {
        $this->expectException(InvalidConfigException::class);

        new PlainTextValidator($config);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function incompleteConfigs(): array
    {
        return [
            'без max' => [['min' => 2, 'messagePrefix' => 'name']],
            'без min' => [['max' => 64, 'messagePrefix' => 'name']],
        ];
    }

    private function nameValidator(): PlainTextValidator
    {
        return new PlainTextValidator(['min' => 2, 'max' => 64, 'multiline' => false, 'messagePrefix' => 'name']);
    }

    private function textValidator(): PlainTextValidator
    {
        return new PlainTextValidator(['min' => 1, 'max' => 2000, 'multiline' => true, 'messagePrefix' => 'text']);
    }
}
