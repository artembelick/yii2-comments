<?php

declare(strict_types=1);

namespace backend\tests\regression;

use backend\tests\RegressionTester;
use Codeception\Attribute\DataProvider;
use Codeception\Attribute\Group;
use Codeception\Example;
use common\tests\_support\ErrorPageView;
use common\tests\_support\Examples;
use Yii;
use yii\db\Expression;

/** Страницы ошибок админки (docs/test-plans/regression.md, «Страницы ошибок»). */
#[Group('parallel')]
final class ErrorPageCest
{
    /**
     * REG-A-55: ошибка админки — свой вид в layout админки: русский заголовок кода, общий текст, ссылка «К списку»
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('errors')]
    public function errorShowsOwnPage(RegressionTester $I, Example $example): void
    {
        $I->amSignedInAs($I->haveAdmin());
        $code = Examples::int($example, 'code');

        $I->send(Examples::string($example, 'method'), Examples::string($example, 'url'));

        $I->seeResponseCodeIs($code);
        $I->assertSame([
            'title' => Yii::t('app', sprintf('error.%d.title', $code)),
            'text' => Yii::t('app', sprintf('error.%d.text', $code)),
            'links' => [Yii::t('app', 'error.back.list') => '/'],
        ], ErrorPageView::of($I->grabResponse()));
        $I->dontSeeResponseContains(ErrorPageView::YII_TEMPLATE_LINES[0]);
        $I->dontSeeResponseContains(ErrorPageView::YII_TEMPLATE_LINES[1]);
    }

    /**
     * Ошибки админки с общим текстом. Метод проверяется раньше поиска комментария, поэтому id может не существовать.
     *
     * @return array<string, array{method: string, url: string, code: int}>
     */
    private function errors(): array
    {
        return [
            'правка несуществующего → 404' => ['method' => 'GET', 'url' => '/comments/999999999/edit', 'code' => 404],
            'удаление через GET → 405' => ['method' => 'GET', 'url' => '/comments/999999999/delete', 'code' => 405],
        ];
    }

    /** REG-A-55: 409 правки удалённого — «Сначала восстановите комментарий» и ссылка на «Удалённые» вместо «К списку» */
    public function conflictLinksToDeletedTab(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $deleted = $I->haveComment(['deleted_at' => new Expression('now()'), 'deleted_by' => $admin->id]);
        $I->amSignedInAs($admin);

        $I->sendGet(sprintf('/comments/%d/edit', $deleted->id));

        $I->seeResponseCodeIs(409);
        $I->assertSame([
            'title' => Yii::t('app', 'error.409.title'),
            'text' => Yii::t('app', 'admin.edit_deleted'),
            'links' => [Yii::t('app', 'error.back.deleted') => '/?status=deleted'],
        ], ErrorPageView::of($I->grabResponse()));
        $I->dontSeeResponseContains(ErrorPageView::YII_TEMPLATE_LINES[0]);
        $I->dontSeeResponseContains(ErrorPageView::YII_TEMPLATE_LINES[1]);
    }
}
