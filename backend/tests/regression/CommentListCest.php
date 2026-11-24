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
        $I->dontSeeElement('table');
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
}
