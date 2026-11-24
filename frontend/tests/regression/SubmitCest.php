<?php

declare(strict_types=1);

namespace frontend\tests\regression;

use Codeception\Attribute\DataProvider;
use Codeception\Attribute\Group;
use Codeception\Example;
use common\models\Comment;
use common\tests\_support\Examples;
use frontend\tests\RegressionTester;
use Yii;

#[Group('exclusive')]
final class SubmitCest
{
    /** REG-G-10: валидные имя и текст → 302 на /, флэш, комментарий первым в ленте */
    public function validCommentIsPublished(RegressionTester $I): void
    {
        $text = 'Комментарий гостя ' . $I->fixtureFactory()->tag();

        $I->postComment(['name' => 'Ян', 'text' => $text]);

        $I->seeResponseCodeIs(302);
        $I->seeHttpHeader('Location', $I->publicUrl('/'));
        $I->assertSame([$text], array_slice($I->grabFeedTexts('/'), 0, 1));
        $I->see(Yii::t('app', 'comment.created'), '[role="status"]');
    }

    /** REG-G-11: POST без CSRF-токена → 400, ничего не сохранено */
    public function postWithoutCsrfIsRejected(RegressionTester $I): void
    {
        $text = 'Без токена ' . $I->fixtureFactory()->tag();
        $I->resetCommentLimit();
        $I->amOnPage('/');

        $I->sendPost('/comments', ['name' => 'Ян', 'text' => $text]);

        $I->seeResponseCodeIs(400);
        $I->dontSeeRecord(Comment::class, ['text' => $text]);
    }

    /** REG-G-12: CSRF-токен админки (чужое приложение) → 400, ничего не сохранено */
    public function foreignCsrfTokenIsRejected(RegressionTester $I): void
    {
        $text = 'Чужой токен ' . $I->fixtureFactory()->tag();
        $foreignToken = $I->grabCsrfTokenAt($I->adminUrl('/login'));
        $I->resetCommentLimit();
        $I->amOnUrl($I->publicUrl('/'));

        $I->sendPost('/comments', [RegressionTester::CSRF_PARAM => $foreignToken, 'name' => 'Ян', 'text' => $text]);

        $I->seeResponseCodeIs(400);
        $I->dontSeeRecord(Comment::class, ['text' => $text]);
    }

    /** REG-G-12: протухший CSRF-токен (cookie сменилась после выдачи токена) → 400, ничего не сохранено */
    public function staleCsrfTokenIsRejected(RegressionTester $I): void
    {
        $text = 'Протухший токен ' . $I->fixtureFactory()->tag();
        $staleToken = $I->grabCsrfTokenAt($I->publicUrl('/'));
        $I->resetCommentLimit();
        $I->resetCookie(RegressionTester::CSRF_PARAM);
        $I->amOnPage('/');

        $I->sendPost('/comments', [RegressionTester::CSRF_PARAM => $staleToken, 'name' => 'Ян', 'text' => $text]);

        $I->seeResponseCodeIs(400);
        $I->dontSeeRecord(Comment::class, ['text' => $text]);
    }

    /**
     * REG-G-13: пустое, пробельное или однобуквенное имя → 422, ошибка поля «Имя»
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('invalidNames')]
    public function invalidNameIsRejected(RegressionTester $I, Example $example): void
    {
        $text = 'Имя не прошло ' . $I->fixtureFactory()->tag();

        $I->postComment(['name' => Examples::string($example, 'name'), 'text' => $text]);

        $I->seeResponseCodeIs(422);
        $I->seeFieldError(Examples::string($example, 'error'));
        $I->dontSeeRecord(Comment::class, ['text' => $text]);
    }

    /**
     * @return array<string, array{name: string, error: string}>
     */
    private function invalidNames(): array
    {
        return [
            'пустое' => ['name' => '', 'error' => 'name.required'],
            'из пробелов' => ['name' => '   ', 'error' => 'name.required'],
            'из одной буквы' => ['name' => 'Я', 'error' => 'name.too_short'],
        ];
    }

    /**
     * REG-G-13a: имя из 2 символов проходит, пробелы по краям обрезаются
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('shortNames')]
    public function shortNameIsTrimmedAndSaved(RegressionTester $I, Example $example): void
    {
        $text = 'Короткое имя ' . $I->fixtureFactory()->tag();

        $I->postComment(['name' => Examples::string($example, 'name'), 'text' => $text]);

        $I->seeResponseCodeIs(302);
        $I->seeRecord(Comment::class, ['author' => 'Ян', 'text' => $text]);
    }

    /**
     * @return array<string, array{name: string}>
     */
    private function shortNames(): array
    {
        return [
            '«Ян»' => ['name' => 'Ян'],
            '«  Ян  »' => ['name' => '  Ян  '],
        ];
    }

    /**
     * REG-G-14: имя из 64 символов кириллицы или 64 эмодзи → 302
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('longestNames')]
    public function longestNameIsSaved(RegressionTester $I, Example $example): void
    {
        $text = 'Длинное имя ' . $I->fixtureFactory()->tag();

        $I->postComment(['name' => Examples::string($example, 'name'), 'text' => $text]);

        $I->seeResponseCodeIs(302);
        $I->seeRecord(Comment::class, ['author' => Examples::string($example, 'name'), 'text' => $text]);
    }

    /**
     * @return array<string, array{name: string}>
     */
    private function longestNames(): array
    {
        return [
            '64 символа кириллицы' => ['name' => str_repeat('ж', 64)],
            '64 эмодзи' => ['name' => str_repeat("\u{1F600}", 64)],
        ];
    }

    /** REG-G-14a: имя из 65 символов → 422 */
    public function tooLongNameIsRejected(RegressionTester $I): void
    {
        $text = 'Слишком длинное имя ' . $I->fixtureFactory()->tag();

        $I->postComment(['name' => str_repeat('ж', 65), 'text' => $text]);

        $I->seeResponseCodeIs(422);
        $I->seeFieldError('name.too_long');
        $I->dontSeeRecord(Comment::class, ['text' => $text]);
    }

    /**
     * REG-G-15: пустой или пробельный текст → 422, ошибка поля «Текст»
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('blankTexts')]
    public function blankTextIsRejected(RegressionTester $I, Example $example): void
    {
        $name = 'Ян ' . $I->fixtureFactory()->tag();

        $I->postComment(['name' => $name, 'text' => Examples::string($example, 'text')]);

        $I->seeResponseCodeIs(422);
        $I->seeFieldError('text.required');
        $I->dontSeeRecord(Comment::class, ['author' => $name]);
    }

    /**
     * @return array<string, array{text: string}>
     */
    private function blankTexts(): array
    {
        return [
            'пустой' => ['text' => ''],
            'из пробелов и переносов' => ['text' => " \n \n "],
        ];
    }

    /** REG-G-15a: текст из одного символа → 302 */
    public function oneCharacterTextIsSaved(RegressionTester $I): void
    {
        $name = 'Ян ' . $I->fixtureFactory()->tag();

        $I->postComment(['name' => $name, 'text' => 'a']);

        $I->seeResponseCodeIs(302);
        $I->seeRecord(Comment::class, ['author' => $name, 'text' => 'a']);
    }

    /**
     * REG-G-16: текст из 2000 символов → 302, \r\n сохранён как \n
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('longestTexts')]
    public function longestTextIsSaved(RegressionTester $I, Example $example): void
    {
        $name = 'Ян ' . $I->fixtureFactory()->tag();

        $I->postComment(['name' => $name, 'text' => Examples::string($example, 'text')]);

        $I->seeResponseCodeIs(302);
        $I->seeRecord(Comment::class, ['author' => $name, 'text' => Examples::string($example, 'saved')]);
    }

    /**
     * @return array<string, array{text: string, saved: string}>
     */
    private function longestTexts(): array
    {
        return [
            '2000 символов' => ['text' => str_repeat('ж', 2000), 'saved' => str_repeat('ж', 2000)],
            '2000 символов с \r\n' => [
                'text' => str_repeat("ж\r\n", 666) . 'жж',
                'saved' => str_repeat("ж\n", 666) . 'жж',
            ],
        ];
    }

    /** REG-G-16a: текст из 2001 символа → 422 */
    public function tooLongTextIsRejected(RegressionTester $I): void
    {
        $name = 'Ян ' . $I->fixtureFactory()->tag();

        $I->postComment(['name' => $name, 'text' => str_repeat('ж', 2001)]);

        $I->seeResponseCodeIs(422);
        $I->seeFieldError('text.too_long');
        $I->dontSeeRecord(Comment::class, ['author' => $name]);
    }

    /** REG-G-17: пробелы по краям текста обрезаются */
    public function textIsTrimmed(RegressionTester $I): void
    {
        $text = 'Текст с пробелами ' . $I->fixtureFactory()->tag();

        $I->postComment(['name' => 'Ян', 'text' => "  \n" . $text . "\n  "]);

        $I->seeResponseCodeIs(302);
        $I->seeRecord(Comment::class, ['text' => $text]);
    }

    /**
     * REG-G-18: перенос в имени, управляющие символы и невалидный UTF-8 → 422, а не 500
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('invalidCharacters')]
    public function invalidCharactersAreRejected(RegressionTester $I, Example $example): void
    {
        $tag = $I->fixtureFactory()->tag();
        $field = Examples::string($example, 'field');
        $fields = ['name' => 'Ян ' . $tag, 'text' => 'Текст ' . $tag, $field => Examples::string($example, 'value')];

        $I->postComment($fields);

        $I->seeResponseCodeIs(422);
        $I->seeFieldError(Examples::string($example, 'error'));
    }

    /**
     * @return array<string, array{field: string, value: string, error: string}>
     */
    private function invalidCharacters(): array
    {
        return [
            'перенос в имени' => ['field' => 'name', 'value' => "Ян\nМария", 'error' => 'name.multiline'],
            '\0 в имени' => ['field' => 'name', 'value' => "Ян\0", 'error' => 'name.invalid_chars'],
            '\x07 в имени' => ['field' => 'name', 'value' => "Ян\x07", 'error' => 'name.invalid_chars'],
            '\t в имени' => ['field' => 'name', 'value' => "Ян\tМария", 'error' => 'name.invalid_chars'],
            '\0 в тексте' => ['field' => 'text', 'value' => "Текст\0", 'error' => 'text.invalid_chars'],
            '\x07 в тексте' => ['field' => 'text', 'value' => "Текст\x07", 'error' => 'text.invalid_chars'],
            '\t в тексте' => ['field' => 'text', 'value' => "Текст\tещё", 'error' => 'text.invalid_chars'],
            'невалидный UTF-8 в имени' => ['field' => 'name', 'value' => "Ян\xC3\x28", 'error' => 'name.invalid_chars'],
            'невалидный UTF-8 в тексте' => ['field' => 'text', 'value' => "Текст\xC3\x28", 'error' => 'text.invalid_chars'],
        ];
    }

    /**
     * REG-G-19: поле отсутствует или пришло массивом → 422, а не 500
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('malformedFields')]
    public function malformedFieldIsRejected(RegressionTester $I, Example $example): void
    {
        $text = 'Кривое поле ' . $I->fixtureFactory()->tag();

        $I->postComment([...Examples::array($example, 'fields'), 'text' => $text]);

        $I->seeResponseCodeIs(422);
        $I->seeFieldError(Examples::string($example, 'error'));
        $I->dontSeeRecord(Comment::class, ['text' => $text]);
    }

    /**
     * @return array<string, array{fields: array<string, mixed>, error: string}>
     */
    private function malformedFields(): array
    {
        return [
            'имени нет' => ['fields' => [], 'error' => 'name.required'],
            'имя массивом name[]=x' => ['fields' => ['name' => ['x']], 'error' => 'field.invalid'],
        ];
    }

    /** REG-G-20: лишние поля id, status, created_at, updated_by, deleted_by → 302, проигнорированы */
    public function extraFieldsAreIgnored(RegressionTester $I): void
    {
        $text = 'Лишние поля ' . $I->fixtureFactory()->tag();

        $I->postComment([
            'name' => 'Ян',
            'text' => $text,
            'id' => '1',
            'status' => 'deleted',
            'created_at' => '2000-01-01 00:00:00+00',
            'updated_by' => '1',
            'deleted_by' => '1',
        ]);

        $I->seeResponseCodeIs(302);
        $I->seeRecord(Comment::class, ['text' => $text, 'updated_by' => null, 'deleted_by' => null, 'deleted_at' => null]);
        $I->assertSame([$text], array_slice($I->grabFeedTexts('/'), 0, 1));
    }

    /** REG-G-21: ошибка валидации → 422, форма с введёнными значениями и текстами ошибок */
    public function invalidFormKeepsInput(RegressionTester $I): void
    {
        $text = 'Введённый текст ' . $I->fixtureFactory()->tag();

        $I->postComment(['name' => 'Я', 'text' => $text]);

        $I->seeResponseCodeIs(422);
        $I->seeFieldError('name.too_short');
        $I->seeResponseContains('name="name" value="Я"');
        $I->seeResponseContains('>' . $text . '</textarea>');
    }
}
