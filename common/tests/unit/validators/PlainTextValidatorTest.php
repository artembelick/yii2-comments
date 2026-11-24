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

    #[TestDox('UNIT-VAL-01: эмодзи «семья» считается как 5 символов')]
    public function testFamilyEmojiCountsAsFiveCharacters(): void
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $validator = new PlainTextValidator(['min' => 5, 'max' => 5, 'messagePrefix' => 'name']);

        $valid = $validator->validate($family, $error);

        self::assertSame([true, null], [$valid, $error]);
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

    #[TestDox('UNIT-VAL-09: на поле одна ошибка, первая по порядку: тип → обязательность → длина')]
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
            'символы не проверяются: табуляция, перенос и длина → name.too_long' => ["\t\n\0" . $tooLong, 'name.too_long'],
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
        return new PlainTextValidator(['min' => 2, 'max' => 64, 'messagePrefix' => 'name']);
    }

    private function textValidator(): PlainTextValidator
    {
        return new PlainTextValidator(['min' => 1, 'max' => 2000, 'messagePrefix' => 'text']);
    }
}
