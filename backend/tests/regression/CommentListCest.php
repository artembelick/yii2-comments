<?php

declare(strict_types=1);

namespace backend\tests\regression;

use backend\tests\RegressionTester;
use Codeception\Attribute\DataProvider;
use Codeception\Attribute\Group;
use Codeception\Example;
use common\tests\_support\Examples;
use Yii;
use yii\db\Expression;

final class CommentListCest
{
    /** REG-A-30: «Активные» — только активные, «Удалённые» — только удалённые, новые сверху, 20 на странице */
    #[Group('exclusive')]
    public function tabsSplitCommentsNewestFirst(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $newestActiveFirst = array_reverse($I->haveComments(21));
        $newestDeletedFirst = array_reverse($I->haveComments(2, ['deleted_at' => new Expression('now()'), 'deleted_by' => $admin->id]));
        $I->amSignedInAs($admin);

        $pages = [
            'active1' => $I->grabTextsOfPage('/'),
            'active2' => $I->grabTextsOfPage('/?page=2'),
            'deleted1' => $I->grabTextsOfPage('/?status=deleted'),
        ];

        $I->assertSame(array_slice($newestActiveFirst, 0, 20), $pages['active1']);
        $I->assertSame(array_slice($newestActiveFirst, 20, 1), array_slice($pages['active2'], 0, 1));
        $I->assertSame($newestDeletedFirst, array_slice($pages['deleted1'], 0, 2));
        $I->assertSame([], array_values(array_intersect($newestActiveFirst, $pages['deleted1'])));
    }

    /** REG-A-31: у комментария, изменённого Админом 1, «изменил: <его логин>», у удалённого Админом 2 — «удалил: <его логин>» */
    #[Group('exclusive')]
    public function rowsShowWhoChangedAndDeleted(RegressionTester $I): void
    {
        $admin1 = $I->haveAdmin();
        $admin2 = $I->haveAdmin();
        $updated = $I->haveComment(['updated_at' => new Expression('now()'), 'updated_by' => $admin1->id]);
        $deleted = $I->haveComment(['deleted_at' => new Expression('now()'), 'deleted_by' => $admin2->id]);
        $I->amSignedInAs($admin1);

        $audits = [
            'active' => $I->grabAuditOf('/', $updated->text),
            'deleted' => $I->grabAuditOf('/?status=deleted', $deleted->text),
        ];

        $I->assertSame([
            'active' => Yii::t('app', 'admin.audit.updated', ['login' => $admin1->username]),
            'deleted' => Yii::t('app', 'admin.audit.deleted', ['login' => $admin2->username]),
        ], $audits);
    }

    /**
     * REG-A-32: вкладка без комментариев → её пустое состояние
     *
     * @param Example<string, mixed> $example
     */
    #[Group('pristine')]
    #[DataProvider('tabs')]
    public function emptyTabShowsEmptyState(RegressionTester $I, Example $example): void
    {
        $I->amSignedInAs($I->haveAdmin());

        $I->amOnPage(Examples::string($example, 'url'));

        $I->seeResponseCodeIs(200);
        $I->see(Yii::t('app', Examples::string($example, 'message')));
        // Таблица есть, но скрыта: в неё встаёт комментарий, пришедший по WS.
        $I->seeElement('table[hidden]');
        $I->dontSeeElement('table:not([hidden])');
    }

    /**
     * @return array<string, array{url: string, message: string}>
     */
    private function tabs(): array
    {
        return [
            '«Активные»' => ['url' => '/', 'message' => 'admin.empty.active'],
            '«Удалённые»' => ['url' => '/?status=deleted', 'message' => 'admin.empty.deleted'],
        ];
    }

    /** REG-A-33: на странице списка есть шаблон строки и адрес WS */
    #[Group('parallel')]
    public function listPageCarriesRowTemplateAndWsUrl(RegressionTester $I): void
    {
        $I->amSignedInAs($I->haveAdmin());

        $I->amOnPage('/');

        $I->seeElement('template[data-row-template]');
        $I->assertSame('ws://admin.comments.localhost/ws', $I->grabAttributeFrom('[data-ws-url]', 'data-ws-url'));
    }

    /**
     * REG-A-61: JSON-список «Активных» — новые сверху, 20 на странице
     *
     * @param Example<string, mixed> $example
     */
    #[Group('exclusive')]
    #[DataProvider('jsonPages')]
    public function jsonListPagesActiveNewestFirst(RegressionTester $I, Example $example): void
    {
        $newestFirst = array_reverse($I->haveComments(21));
        $I->amSignedInAs($I->haveAdmin());
        $length = Examples::int($example, 'length');

        $items = $I->grabJsonList('active', Examples::int($example, 'page'))['items'];

        $I->assertSame(array_slice($newestFirst, Examples::int($example, 'offset'), $length), array_slice(array_column($items, 'text'), 0, $length));
        $I->assertSame(array_fill(0, $length, 'active'), array_slice(array_column($items, 'status'), 0, $length));
    }

    /**
     * REG-A-61: JSON-список «Удалённых» — новые сверху, 20 на странице
     *
     * @param Example<string, mixed> $example
     */
    #[Group('exclusive')]
    #[DataProvider('jsonPages')]
    public function jsonListPagesDeletedNewestFirst(RegressionTester $I, Example $example): void
    {
        $admin = $I->haveAdmin();
        $newestFirst = array_reverse($I->haveComments(21, ['deleted_at' => new Expression('now()'), 'deleted_by' => $admin->id]));
        $I->amSignedInAs($admin);
        $length = Examples::int($example, 'length');

        $items = $I->grabJsonList('deleted', Examples::int($example, 'page'))['items'];

        $I->assertSame(array_slice($newestFirst, Examples::int($example, 'offset'), $length), array_slice(array_column($items, 'text'), 0, $length));
        $I->assertSame(array_fill(0, $length, 'deleted'), array_slice(array_column($items, 'status'), 0, $length));
    }

    /**
     * Страница и какие из 21 комментария (новые сверху) на ней первыми. На стр. 2 после нашего могут идти чужие.
     *
     * @return array<string, array{page: int, offset: int, length: int}>
     */
    private function jsonPages(): array
    {
        return [
            'стр. 1 — 20 новых' => ['page' => 1, 'offset' => 0, 'length' => 20],
            'стр. 2 — 21-й' => ['page' => 2, 'offset' => 20, 'length' => 1],
        ];
    }

    /** REG-A-61: комментарий в JSON-списке — поля контракта */
    #[Group('exclusive')]
    public function jsonListItemHasContractFields(RegressionTester $I): void
    {
        $I->haveComment();
        $I->amSignedInAs($I->haveAdmin());

        $items = $I->grabJsonList('active', 1)['items'];

        $I->assertSame(
            [['id', 'author', 'text', 'status', 'createdAt', 'updatedAt', 'updatedBy', 'deletedBy']],
            array_map(array_keys(...), array_slice($items, 0, 1)),
        );
    }

    /**
     * REG-A-61a: JSON-список с неизвестным или пустым status → 400
     *
     * @param Example<string, mixed> $example
     */
    #[Group('parallel')]
    #[DataProvider('badStatuses')]
    public function jsonListRejectsUnknownStatus(RegressionTester $I, Example $example): void
    {
        $I->amSignedInAs($I->haveAdmin());

        $I->sendGet('/api/comments', ['status' => Examples::string($example, 'status')]);

        $I->seeResponseCodeIs(400);
        $I->seeResponseContainsJson(['error' => 'invalid_status']);
    }

    /**
     * @return array<string, array{status: string}>
     */
    private function badStatuses(): array
    {
        return [
            'status=foo' => ['status' => 'foo'],
            'status пустой' => ['status' => ''],
        ];
    }
}
