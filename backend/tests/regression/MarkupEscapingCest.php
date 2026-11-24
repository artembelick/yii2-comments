<?php

declare(strict_types=1);

namespace backend\tests\regression;

use backend\tests\RegressionTester;
use Codeception\Attribute\DataProvider;
use Codeception\Attribute\Group;
use Codeception\Example;
use common\tests\_support\Examples;
use yii\db\Expression;

/**
 * Разметка в имени и тексте комментария в HTML-админке (docs/test-plans/regression.md, REG-A-34). Сервер выводит
 * строки списка и форму правки сам, поэтому stored XSS здесь бил бы прямо в админа. Комментарий старше всех на
 * стенде: он последний на последней странице вкладки (`?page=999`). Тест опирается на глобальный порядок таблицы,
 * поэтому он в ярусе `exclusive`: вставка другим тестом в то же время сдвинула бы строку на другую страницу.
 */
#[Group('exclusive')]
final class MarkupEscapingCest
{
    /** Селектор необработанных тегов из провайдера внутри `%1$s`: если они в строке или в форме, разметка исполнилась бы. */
    private const string RAW_TAG_SELECTOR = '%1$s script, %1$s img, %1$s svg';

    /**
     * REG-A-34: разметка в имени и тексте активного комментария на «Активных» показана текстом
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('markup')]
    public function activeTabShowsMarkupAsText(RegressionTester $I, Example $example): void
    {
        $markup = Examples::string($example, 'markup') . ' ' . $I->fixtureFactory()->tag();
        $comment = $I->haveComment([Examples::string($example, 'column') => $markup, 'created_at' => $this->olderThanAll()]);
        $row = sprintf('tr[data-id="%d"]', $comment->id);
        $I->amSignedInAs($I->haveAdmin());

        $I->amOnPage('/?page=999');

        $I->seeResponseCodeIs(200);
        $I->assertSame(
            ['author' => $comment->author, 'text' => $comment->text],
            ['author' => $I->grabTextFrom($row . ' [data-field="author"]'), 'text' => $I->grabTextFrom($row . ' [data-field="text"]')],
        );
        $I->dontSeeInSource($markup);
        $I->dontSeeElement(sprintf(self::RAW_TAG_SELECTOR, $row));
    }

    /**
     * REG-A-34: разметка в имени и тексте удалённого комментария на «Удалённых» показана текстом
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('markup')]
    public function deletedTabShowsMarkupAsText(RegressionTester $I, Example $example): void
    {
        $admin = $I->haveAdmin();
        $markup = Examples::string($example, 'markup') . ' ' . $I->fixtureFactory()->tag();
        $comment = $I->haveComment([
            Examples::string($example, 'column') => $markup,
            'created_at' => $this->olderThanAll(),
            'deleted_at' => new Expression('now()'),
            'deleted_by' => $admin->id,
        ]);
        $row = sprintf('tr[data-id="%d"]', $comment->id);
        $I->amSignedInAs($admin);

        $I->amOnPage('/?status=deleted&page=999');

        $I->seeResponseCodeIs(200);
        $I->assertSame(
            ['author' => $comment->author, 'text' => $comment->text],
            ['author' => $I->grabTextFrom($row . ' [data-field="author"]'), 'text' => $I->grabTextFrom($row . ' [data-field="text"]')],
        );
        $I->dontSeeInSource($markup);
        $I->dontSeeElement(sprintf(self::RAW_TAG_SELECTOR, $row));
    }

    /**
     * REG-A-34: разметка в имени и тексте показана в форме правки значениями полей, из атрибута не выходит
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('markup')]
    public function editFormShowsMarkupAsValues(RegressionTester $I, Example $example): void
    {
        $markup = Examples::string($example, 'markup') . ' ' . $I->fixtureFactory()->tag();
        $comment = $I->haveComment([Examples::string($example, 'column') => $markup]);
        $I->amSignedInAs($I->haveAdmin());

        $I->amOnPage(sprintf('/comments/%d/edit', $comment->id));

        $I->seeResponseCodeIs(200);
        $I->assertSame(
            ['author' => $comment->author, 'text' => $comment->text],
            ['author' => $I->grabValueFrom('#comment-author'), 'text' => $I->grabValueFrom('textarea[name="text"]')],
        );
        $I->dontSeeInSource($markup);
        $I->dontSeeElement(sprintf(self::RAW_TAG_SELECTOR, 'main'));
    }

    /**
     * Разметка и поле, в которое она попадает. `"><svg …>` закрывает атрибут value, если он не экранирован.
     *
     * @return array<string, array{column: string, markup: string}>
     */
    private function markup(): array
    {
        return [
            '<script> в имени' => ['column' => 'author', 'markup' => '<script>window.__xss=1</script>'],
            '<img onerror> в имени' => ['column' => 'author', 'markup' => '<img src=x onerror="window.__xss=1">'],
            'выход из атрибута в имени' => ['column' => 'author', 'markup' => '"><svg onload="window.__xss=1">'],
            '<script> в тексте' => ['column' => 'text', 'markup' => '<script>window.__xss=1</script>'],
            '<img onerror> в тексте' => ['column' => 'text', 'markup' => '<img src=x onerror="window.__xss=1">'],
            'выход из атрибута в тексте' => ['column' => 'text', 'markup' => '"><svg onload="window.__xss=1">'],
        ];
    }

    /** created_at старше всех комментариев на стенде: строка последняя во вкладке при любых чужих данных. */
    private function olderThanAll(): Expression
    {
        return new Expression("(SELECT coalesce(min(created_at), now()) FROM comment) - interval '1 day'");
    }
}
