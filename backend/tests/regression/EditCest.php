<?php

declare(strict_types=1);

namespace backend\tests\regression;

use backend\tests\RegressionTester;
use Codeception\Attribute\DataProvider;
use Codeception\Attribute\Group;
use Codeception\Example;
use common\tests\_support\Examples;
use Yii;
use yii\helpers\Html;

/** Правка текста комментария админом (docs/test-plans/regression.md, «Правка»). */
#[Group('parallel')]
final class EditCest
{
    private const string DELETED_AT = '2026-11-24 11:00:00+00';

    private const string UPDATED_AT = '2026-11-24 10:30:00+00';

    /** REG-A-40: GET правки активного → 200, форма с текущим текстом, имя только для чтения */
    public function editPageShowsCurrentText(RegressionTester $I): void
    {
        $comment = $I->haveComment();
        $I->amSignedInAs($I->haveAdmin());

        $I->amOnPage(sprintf('/comments/%d/edit', $comment->id));

        $I->seeResponseCodeIs(200);
        $I->see(Yii::t('app', 'admin.edit.title'), 'h1');
        $I->seeInField('textarea[name="text"]', $comment->text);
        $I->seeElement('input[readonly][value="' . Html::encode($comment->author) . '"]');
        $I->dontSeeElement('[name="name"]');
    }

    /** REG-A-41: валидный новый текст → 302 в список и флэш, текст, updated_at и updated_by = Админ 1 изменились, имя прежнее */
    public function newTextIsSaved(RegressionTester $I): void
    {
        $admin1 = $I->haveAdmin();
        $comment = $I->haveComment();
        $newText = 'Новый текст ' . $I->fixtureFactory()->tag();
        $I->amSignedInAs($admin1);

        $I->submitAdminForm(sprintf('/comments/%d/edit', $comment->id), ['text' => $newText]);

        $I->followRedirectAndSeeFlash('/', 'admin.updated');
        $I->assertSame(
            ['author' => $comment->author, 'text' => $newText, 'updated_by' => $admin1->id],
            $I->grabCommentColumns($comment->id, ['author', 'text', 'updated_by']),
        );
        $I->assertNotNull($I->grabCommentColumns($comment->id, ['updated_at'])['updated_at']);
    }

    /**
     * REG-A-42: недопустимый текст → 422 и ошибка поля, как у гостя (G-15, G-16a, G-18a)
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('invalidTexts')]
    public function invalidTextIsRejected(RegressionTester $I, Example $example): void
    {
        $comment = $I->haveComment();
        $I->amSignedInAs($I->haveAdmin());

        $I->submitAdminForm(sprintf('/comments/%d/edit', $comment->id), ['text' => Examples::string($example, 'text')]);

        $I->seeResponseCodeIs(422);
        $I->seeFieldError(Examples::string($example, 'error'));
        $I->assertSame(['text' => $comment->text, 'updated_by' => null], $I->grabCommentColumns($comment->id, ['text', 'updated_by']));
    }

    /**
     * @return array<string, array{text: string, error: string}>
     */
    private function invalidTexts(): array
    {
        return [
            'пустой' => ['text' => '', 'error' => 'text.required'],
            'из пробелов и переносов' => ['text' => " \n \n ", 'error' => 'text.required'],
            '2001 символ' => ['text' => str_repeat('ж', 2001), 'error' => 'text.too_long'],
            'из одних вырезаемых символов' => ['text' => "\0\x07\u{2066}\xC3", 'error' => 'text.required'],
            '2001 символ после очистки' => ['text' => str_repeat('ж', 2001) . "\0", 'error' => 'text.too_long'],
        ];
    }

    /** REG-A-42: при 422 форма показывает текст уже очищенным, как у гостя (G-21) */
    public function invalidTextIsShownCleaned(RegressionTester $I): void
    {
        $comment = $I->haveComment();
        $tooLongAfterCleaning = str_repeat('ж', 2000) . "\u{202E}ж\0";
        $I->amSignedInAs($I->haveAdmin());

        $I->submitAdminForm(sprintf('/comments/%d/edit', $comment->id), ['text' => $tooLongAfterCleaning]);

        $I->seeResponseCodeIs(422);
        $I->seeFieldError('text.too_long');
        $I->seeResponseContains('>' . str_repeat('ж', 2001) . '</textarea>');
    }

    /**
     * REG-A-42: граничный допустимый текст → сохранён очищенным, как у гостя (G-15a, G-16, G-17, G-18)
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('boundaryTexts')]
    public function boundaryTextIsSaved(RegressionTester $I, Example $example): void
    {
        $comment = $I->haveComment();
        $I->amSignedInAs($I->haveAdmin());

        $I->submitAdminForm(sprintf('/comments/%d/edit', $comment->id), ['text' => Examples::string($example, 'text')]);

        $I->seeResponseCodeIs(302);
        $I->assertSame(['text' => Examples::string($example, 'saved')], $I->grabCommentColumns($comment->id, ['text']));
    }

    /**
     * @return array<string, array{text: string, saved: string}>
     */
    private function boundaryTexts(): array
    {
        return [
            'один символ' => ['text' => 'a', 'saved' => 'a'],
            '2000 символов' => ['text' => str_repeat('ж', 2000), 'saved' => str_repeat('ж', 2000)],
            '2000 символов с \r\n' => ['text' => str_repeat("ж\r\n", 666) . 'жж', 'saved' => str_repeat("ж\n", 666) . 'жж'],
            'пробелы по краям' => ['text' => "  \nТекст с пробелами\n  ", 'saved' => 'Текст с пробелами'],
            '\0 в тексте' => ['text' => "Текст\0", 'saved' => 'Текст'],
            '\x07 в тексте' => ['text' => "Текст\x07ещё", 'saved' => 'Текстещё'],
            '\t в тексте' => ['text' => "Текст\tещё", 'saved' => 'Текст ещё'],
            'bidi в тексте' => ['text' => "Текст\u{202E}ещё", 'saved' => 'Текстещё'],
            'U+2028 в тексте' => ['text' => "первая\u{2028}вторая", 'saved' => "первая\nвторая"],
            'невалидный UTF-8' => ['text' => "Текст\xFFещё", 'saved' => 'Текстещё'],
            '2000 символов после очистки' => ['text' => str_repeat('ж', 2000) . "\u{202E}", 'saved' => str_repeat('ж', 2000)],
        ];
    }

    /** REG-A-43: лишние поля name, status, created_at, deleted_by проигнорированы */
    public function extraFieldsAreIgnored(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $comment = $I->haveComment();
        $newText = 'Новый текст ' . $I->fixtureFactory()->tag();
        $I->amSignedInAs($admin);
        $columns = ['author', 'created_at', 'deleted_at', 'deleted_by'];
        $before = $I->grabCommentColumns($comment->id, $columns);

        $I->submitAdminForm(sprintf('/comments/%d/edit', $comment->id), [
            'text' => $newText,
            'name' => 'Подменённое имя',
            'status' => 'deleted',
            'created_at' => '2000-01-01 00:00:00+00',
            'deleted_by' => (string) $admin->id,
        ]);

        $I->seeResponseCodeIs(302);
        $I->assertSame($before, $I->grabCommentColumns($comment->id, $columns));
        $I->assertSame(['text' => $newText], $I->grabCommentColumns($comment->id, ['text']));
    }

    /**
     * REG-A-44: несуществующий id или id не числом → 404
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('unknownIds')]
    public function unknownIdIsNotFound(RegressionTester $I, Example $example): void
    {
        $I->amSignedInAs($I->haveAdmin());

        $I->amOnPage(sprintf('/comments/%s/edit', Examples::string($example, 'id')));

        $I->seeResponseCodeIs(404);
    }

    /**
     * @return array<string, array{id: string}>
     */
    private function unknownIds(): array
    {
        return [
            'несуществующий' => ['id' => '999999999'],
            'abc' => ['id' => 'abc'],
        ];
    }

    /** REG-A-45: GET правки удалённого → 409 и «Сначала восстановите комментарий» */
    public function editPageOfDeletedIsConflict(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $comment = $I->haveComment(['deleted_at' => self::DELETED_AT, 'deleted_by' => $admin->id]);
        $I->amSignedInAs($admin);

        $I->amOnPage(sprintf('/comments/%d/edit', $comment->id));

        $I->seeResponseCodeIs(409);
        $I->see(Yii::t('app', 'admin.edit_deleted'));
    }

    /** REG-A-45: сохранение удалённого → 409 и «Сначала восстановите комментарий», данные не изменились */
    public function savingDeletedIsConflict(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $comment = $I->haveComment(['deleted_at' => self::DELETED_AT, 'deleted_by' => $admin->id]);
        $I->amSignedInAs($admin);

        $I->submitAdminForm(sprintf('/comments/%d/edit', $comment->id), ['text' => 'Новый текст']);

        $I->seeResponseCodeIs(409);
        $I->seeResponseContains(Html::encode(Yii::t('app', 'admin.edit_deleted')));
        $I->assertSame(['text' => $comment->text, 'updated_by' => null], $I->grabCommentColumns($comment->id, ['text', 'updated_by']));
    }

    /**
     * REG-A-46: тот же текст, в том числе с пробелами по краям, \r\n или вырезаемыми символами → 302 и «Изменений нет», аудит прежний
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('sameTexts')]
    public function sameTextIsUnchanged(RegressionTester $I, Example $example): void
    {
        $comment = $I->haveComment(['text' => "первая\nвторая " . $I->fixtureFactory()->tag()]);
        $I->amSignedInAs($I->haveAdmin());

        $I->submitAdminForm(sprintf('/comments/%d/edit', $comment->id), ['text' => sprintf(Examples::string($example, 'format'), $comment->text)]);

        $I->followRedirectAndSeeFlash('/', 'admin.unchanged');
        $I->assertSame(['updated_at' => null, 'updated_by' => null], $I->grabCommentColumns($comment->id, ['updated_at', 'updated_by']));
    }

    /**
     * Как текущий текст присылается обратно: sprintf-шаблон вокруг него. \r\n вместо \n — отдельный тест ниже.
     *
     * @return array<string, array{format: string}>
     */
    private function sameTexts(): array
    {
        return [
            'тот же текст' => ['format' => '%s'],
            'пробелы по краям' => ['format' => "  %s \n"],
            'вырезаемые символы' => ['format' => "\0\u{202E}%s\x07\xFF"],
        ];
    }

    /** REG-A-46: тот же текст с \r\n вместо \n → «Изменений нет», аудит прежний */
    public function sameTextWithCrlfIsUnchanged(RegressionTester $I): void
    {
        $tag = $I->fixtureFactory()->tag();
        $comment = $I->haveComment(['text' => "первая\nвторая " . $tag]);
        $I->amSignedInAs($I->haveAdmin());

        $I->submitAdminForm(sprintf('/comments/%d/edit', $comment->id), ['text' => "первая\r\nвторая " . $tag]);

        $I->followRedirectAndSeeFlash('/', 'admin.unchanged');
        $I->assertSame(['updated_at' => null, 'updated_by' => null], $I->grabCommentColumns($comment->id, ['updated_at', 'updated_by']));
    }

    /** REG-A-47: Админ 1 сохранил свой текст, затем Админ 2 сохраняет другой по той же форме → сохранён текст Админа 2, updated_by = Админ 2 */
    public function laterSaveWins(RegressionTester $I): void
    {
        $admin1 = $I->haveAdmin();
        $admin2 = $I->haveAdmin();
        $savedByAdmin1 = $I->haveComment([
            'text' => 'Текст Админа 1 ' . $I->fixtureFactory()->tag(),
            'updated_at' => self::UPDATED_AT,
            'updated_by' => $admin1->id,
        ]);
        $admin2Text = 'Текст Админа 2 ' . $I->fixtureFactory()->tag();
        $I->amSignedInAs($admin2);

        $I->submitAdminForm(sprintf('/comments/%d/edit', $savedByAdmin1->id), ['text' => $admin2Text]);

        $I->seeResponseCodeIs(302);
        $I->assertSame(['text' => $admin2Text, 'updated_by' => $admin2->id], $I->grabCommentColumns($savedByAdmin1->id, ['text', 'updated_by']));
    }

    /** REG-A-48: сохранение без CSRF → 400, данные не изменились */
    public function saveWithoutCsrfIsRejected(RegressionTester $I): void
    {
        $comment = $I->haveComment();
        $I->amSignedInAs($I->haveAdmin());

        $I->submitAdminForm(sprintf('/comments/%d/edit', $comment->id), ['text' => 'Новый текст'], withCsrf: false);

        $I->seeResponseCodeIs(400);
        $I->assertSame(['text' => $comment->text], $I->grabCommentColumns($comment->id, ['text']));
    }

    /** REG-A-48a: PUT на адрес правки → 405: GET открывает форму, POST сохраняет, другого нет */
    public function otherMethodOnEditIsNotAllowed(RegressionTester $I): void
    {
        $comment = $I->haveComment();
        $I->amSignedInAs($I->haveAdmin());

        $I->sendPut(sprintf('/comments/%d/edit', $comment->id));

        $I->seeResponseCodeIs(405);
    }
}
