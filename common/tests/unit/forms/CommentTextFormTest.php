<?php

declare(strict_types=1);

namespace common\tests\unit\forms;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\forms\CommentTextForm;
use common\text\CommentTextNormalizer;
use PHPUnit\Framework\Attributes\TestDox;
use Yii;

final class CommentTextFormTest extends Unit
{
    #[TestDox('UNIT-CTF-01: правила текста как у CommentForm: ошибка из каталога, ровно одна')]
    #[DataProvider('invalidTexts')]
    public function testTextRulesMatchCommentForm(mixed $text, string $expectedKey): void
    {
        $form = $this->form(['text' => $text]);

        $form->validate();

        self::assertSame(['text' => [Yii::t('app', $expectedKey)]], $form->getErrors());
    }

    /**
     * Наборы для поля text из провайдеров UNIT-CF-02 и UNIT-CF-03 и массив, как в UNIT-CF-05.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function invalidTexts(): array
    {
        $invalidCases = array_filter(CommentFormTest::invalidFields(), static fn(array $case): bool => $case[0] === 'text');
        $blankCases = CommentFormTest::blankFields();
        $blankNames = array_map(static fn(string $name): string => $name . ' → text.required', array_keys($blankCases));
        $blankTexts = array_map(static fn(array $case): array => [$case[1], 'text.required'], array_values($blankCases));

        return [
            ...array_map(static fn(array $case): array => [$case[1], $case[2]], $invalidCases),
            ...array_combine($blankNames, $blankTexts),
            'массив → field.invalid' => [['текст'], 'field.invalid'],
        ];
    }

    #[TestDox('UNIT-CTF-01: валидный текст проходит, геттер отдаёт нормализованный')]
    public function testValidTextIsNormalized(): void
    {
        $form = $this->form(['text' => "\r\nпервая\r\nвторая  \n"]);

        $valid = $form->validate();

        self::assertSame([true, "первая\nвторая"], [$valid, $form->getNormalizedText()]);
    }

    #[TestDox('UNIT-CTF-02: isSameAs сравнивает нормализованный ввод с текущим текстом')]
    #[DataProvider('comparisons')]
    public function testIsSameAsComparesNormalizedText(string $input, bool $expected): void
    {
        $form = $this->form(['text' => $input]);

        $same = $form->isSameAs("первая\nвторая");

        self::assertSame($expected, $same);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function comparisons(): array
    {
        return [
            'тот же текст → true' => ["первая\nвторая", true],
            'пробелы по краям → true' => ["  первая\nвторая \n", true],
            '\r\n вместо \n → true' => ["первая\r\nвторая", true],
            'вырезаемые символы → true' => ["\0первая\u{202E}\nвторая\xFF", true],
            'другой текст → false' => ["первая\nтретья", false],
        ];
    }

    #[TestDox('UNIT-CTF-03: safe-атрибут только text, остальные ключи load() игнорирует')]
    public function testOnlyTextIsSafe(): void
    {
        $form = new CommentTextForm(new CommentTextNormalizer());

        $form->load(['text' => 'Новый текст', 'name' => 'Ян', 'status' => 'deleted', 'created_at' => '2000-01-01', 'deleted_by' => '7']);

        self::assertSame([['text'], ['text' => 'Новый текст']], [$form->safeAttributes(), $form->getAttributes()]);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function form(array $post): CommentTextForm
    {
        $form = new CommentTextForm(new CommentTextNormalizer());
        $form->load($post);

        return $form;
    }
}
