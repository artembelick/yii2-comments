<?php

declare(strict_types=1);

namespace common\tests\unit\forms;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\forms\CommentForm;
use common\text\CommentTextNormalizer;
use PHPUnit\Framework\Attributes\TestDox;
use Yii;

final class CommentFormTest extends Unit
{
    private const array VALID = ['name' => 'Ян', 'text' => 'Комментарий'];

    #[TestDox('UNIT-CF-01: валидные данные проходят, геттеры отдают нормализованные значения')]
    public function testValidInputPassesAndIsNormalized(): void
    {
        $form = $this->form(['name' => "  Ян\u{00A0}", 'text' => "\r\nпервая\r\nвторая  \n"]);

        $valid = $form->validate();

        self::assertSame([true, 'Ян', "первая\nвторая"], [$valid, $form->getNormalizedName(), $form->getNormalizedText()]);
    }

    #[TestDox('UNIT-CF-02: ошибка поля — текст из каталога, ровно одна на поле')]
    #[DataProvider('invalidFields')]
    public function testFieldErrorComesFromCatalog(string $field, string $value, string $expectedKey): void
    {
        $form = $this->form([...self::VALID, $field => $value]);

        $form->validate();

        self::assertSame([$field => [Yii::t('app', $expectedKey)]], $form->getErrors());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidFields(): array
    {
        return [
            'имя из 1 символа → name.too_short' => ['name', 'Я', 'name.too_short'],
            'имя из 65 символов → name.too_long' => ['name', str_repeat('ж', 65), 'name.too_long'],
            'перенос в имени → name.multiline' => ['name', "Ян\nМария", 'name.multiline'],
            'NUL в имени → name.invalid_chars' => ['name', "Ян\0", 'name.invalid_chars'],
            'текст из 2001 символа → text.too_long' => ['text', str_repeat('ж', 2001), 'text.too_long'],
            'BEL в тексте → text.invalid_chars' => ['text', "текст\x07", 'text.invalid_chars'],
        ];
    }

    #[TestDox('UNIT-CF-03: пустые после нормализации поля → name.required и text.required')]
    public function testBlankFieldsAreRequired(): void
    {
        $form = $this->form(['name' => " \u{200B} ", 'text' => "\r\n \n"]);

        $form->validate();

        self::assertSame(
            ['name' => [Yii::t('app', 'name.required')], 'text' => [Yii::t('app', 'text.required')]],
            $form->getErrors(),
        );
    }

    #[TestDox('UNIT-CF-04: safe-атрибуты только name и text, остальные ключи load() игнорирует')]
    public function testOnlyNameAndTextAreSafe(): void
    {
        $form = new CommentForm(new CommentTextNormalizer());

        $form->load([...self::VALID, 'id' => '5', 'status' => 'deleted', 'created_at' => '2000-01-01']);

        self::assertSame(
            [['name', 'text'], self::VALID],
            [$form->safeAttributes(), $form->getAttributes()],
        );
    }

    #[TestDox('UNIT-CF-05: поле пришло массивом → field.invalid без исключения')]
    public function testArrayFieldIsInvalid(): void
    {
        $form = $this->form(['name' => ['Ян'], 'text' => ['текст']]);

        $form->validate();

        self::assertSame(
            ['name' => [Yii::t('app', 'field.invalid')], 'text' => [Yii::t('app', 'field.invalid')]],
            $form->getErrors(),
        );
    }

    #[TestDox('UNIT-CF-06: подписи полей — form.name и form.text из каталога')]
    public function testLabelsComeFromCatalog(): void
    {
        $form = new CommentForm(new CommentTextNormalizer());

        $labels = [$form->getAttributeLabel('name'), $form->getAttributeLabel('text')];

        self::assertSame([Yii::t('app', 'form.name'), Yii::t('app', 'form.text')], $labels);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function form(array $post): CommentForm
    {
        $form = new CommentForm(new CommentTextNormalizer());
        $form->load($post);

        return $form;
    }
}
