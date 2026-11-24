<?php

declare(strict_types=1);

namespace backend\tests\regression;

use backend\tests\RegressionTester;
use Codeception\Attribute\DataProvider;
use Codeception\Attribute\Group;
use Codeception\Example;
use common\repositories\CommentStatus;
use common\tests\_support\Examples;

/**
 * Удаление и восстановление (docs/test-plans/regression.md, «Удаление и восстановление»). A-50 и A-52 смотрят первую
 * страницу JSON-списка: параллельные шарды сдвинули бы с неё комментарий теста, поэтому они в ярусе exclusive.
 */
final class DeletionCest
{
    private const string DELETED_AT = '2026-11-24 11:00:00+00';

    /** REG-A-50: Админ 1 удаляет активный → 302 и флэш, он на «Удалённых», не в «Активных», запись есть, deleted_by = Админ 1 */
    #[Group('exclusive')]
    public function adminDeletesActiveComment(RegressionTester $I): void
    {
        $admin1 = $I->haveAdmin();
        $comment = $I->haveComment();
        $I->amSignedInAs($admin1);

        $I->submitAdminForm(sprintf('/comments/%d/delete', $comment->id), ['status' => 'active', 'page' => '1']);

        $I->followRedirectAndSeeFlash('/?status=active&page=1', 'admin.deleted');
        $I->assertSame(['deleted_by' => $admin1->id], $I->grabCommentColumns($comment->id, ['deleted_by']));
        $I->assertContains($comment->text, $I->grabJsonTexts('deleted'));
        $I->assertNotContains($comment->text, $I->grabJsonTexts('active'));
    }

    /** REG-A-51: Админ 2 удаляет уже удалённый Админом 1 → 302 и «Уже удалён», deleted_by остаётся = Админ 1 */
    #[Group('parallel')]
    public function secondDeletionKeepsFirstRemover(RegressionTester $I): void
    {
        $admin1 = $I->haveAdmin();
        $comment = $I->haveComment(['deleted_at' => self::DELETED_AT, 'deleted_by' => $admin1->id]);
        $I->amSignedInAs($I->haveAdmin());

        $I->submitAdminForm(sprintf('/comments/%d/delete', $comment->id), ['status' => 'active', 'page' => '1']);

        $I->followRedirectAndSeeFlash('/?status=active&page=1', 'admin.already_deleted');
        $I->assertSame(
            ['deleted_at' => self::DELETED_AT, 'deleted_by' => $admin1->id],
            $I->grabCommentColumns($comment->id, ['deleted_at', 'deleted_by']),
        );
    }

    /** REG-A-52: восстановление удалённого → 302, снова активен с прежними текстом и датой, deleted_* очищены */
    #[Group('exclusive')]
    public function adminRestoresDeletedComment(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $comment = $I->haveComment(['deleted_at' => self::DELETED_AT, 'deleted_by' => $admin->id]);
        $before = $I->grabCommentColumns($comment->id, ['text', 'created_at']);
        $I->amSignedInAs($admin);

        $I->submitAdminForm(sprintf('/comments/%d/restore', $comment->id), ['status' => 'deleted', 'page' => '2']);

        $I->followRedirectAndSeeFlash('/?status=deleted&page=2', 'admin.restored');
        $I->assertSame(
            [...$before, 'deleted_at' => null, 'deleted_by' => null],
            $I->grabCommentColumns($comment->id, ['text', 'created_at', 'deleted_at', 'deleted_by']),
        );
        $I->assertContains($comment->text, $I->grabJsonTexts('active'));
    }

    /** REG-A-53: восстановление активного → 302 и «Уже активен», данные не изменились */
    #[Group('parallel')]
    public function restoringActiveIsNoop(RegressionTester $I): void
    {
        $comment = $I->haveComment();
        $columns = ['text', 'created_at', 'updated_at', 'updated_by', 'deleted_at', 'deleted_by'];
        $before = $I->grabCommentColumns($comment->id, $columns);
        $I->amSignedInAs($I->haveAdmin());

        $I->submitAdminForm(sprintf('/comments/%d/restore', $comment->id), ['status' => 'active', 'page' => '1']);

        $I->followRedirectAndSeeFlash('/?status=active&page=1', 'admin.already_active');
        $I->assertSame($before, $I->grabCommentColumns($comment->id, $columns));
    }

    /**
     * REG-A-54: удаление и восстановление несуществующего → 404
     *
     * @param Example<string, mixed> $example
     */
    #[Group('parallel')]
    #[DataProvider('actions')]
    public function unknownCommentIsNotFound(RegressionTester $I, Example $example): void
    {
        $I->amSignedInAs($I->haveAdmin());

        $I->submitAdminForm(sprintf('/comments/999999999/%s', Examples::string($example, 'action')), []);

        $I->seeResponseCodeIs(404);
    }

    /**
     * REG-A-54: удаление и восстановление без CSRF → 400, данные не изменились
     *
     * @param Example<string, mixed> $example
     */
    #[Group('parallel')]
    #[DataProvider('actions')]
    public function actionWithoutCsrfIsRejected(RegressionTester $I, Example $example): void
    {
        $admin = $I->haveAdmin();
        $comment = $I->haveCommentWithStatus(Examples::commentStatus($example, 'commentStatus'), $admin);
        $columns = ['deleted_at', 'deleted_by'];
        $before = $I->grabCommentColumns($comment->id, $columns);
        $I->amSignedInAs($admin);

        $I->submitAdminForm(sprintf('/comments/%d/%s', $comment->id, Examples::string($example, 'action')), [], withCsrf: false);

        $I->seeResponseCodeIs(400);
        $I->assertSame($before, $I->grabCommentColumns($comment->id, $columns));
    }

    /**
     * REG-A-54: удаление и восстановление через GET → 405, данные не изменились
     *
     * @param Example<string, mixed> $example
     */
    #[Group('parallel')]
    #[DataProvider('actions')]
    public function actionThroughGetIsNotAllowed(RegressionTester $I, Example $example): void
    {
        $admin = $I->haveAdmin();
        $comment = $I->haveCommentWithStatus(Examples::commentStatus($example, 'commentStatus'), $admin);
        $columns = ['deleted_at', 'deleted_by'];
        $before = $I->grabCommentColumns($comment->id, $columns);
        $I->amSignedInAs($admin);

        $I->amOnPage(sprintf('/comments/%d/%s', $comment->id, Examples::string($example, 'action')));

        $I->seeResponseCodeIs(405);
        $I->assertSame($before, $I->grabCommentColumns($comment->id, $columns));
    }

    /**
     * Действие и статус комментария, который оно изменило бы: тест «данные не изменились» проверяет действие,
     * которое могло бы сработать.
     *
     * @return array<string, array{action: string, commentStatus: CommentStatus}>
     */
    private function actions(): array
    {
        return [
            'удаление' => ['action' => 'delete', 'commentStatus' => CommentStatus::Active],
            'восстановление' => ['action' => 'restore', 'commentStatus' => CommentStatus::Deleted],
        ];
    }
}
