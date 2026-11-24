<?php

declare(strict_types=1);

namespace backend\tests\regression;

use backend\tests\RegressionTester;
use Codeception\Attribute\DataProvider;
use Codeception\Attribute\Group;
use Codeception\Example;
use common\repositories\CommentStatus;
use common\tests\_support\Examples;

#[Group('parallel')]
final class AccessCest
{
    /**
     * REG-A-20: без входа любой маршрут админки → 302 на логин, данные не изменились
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('adminRoutes')]
    public function guestIsRedirectedToLogin(RegressionTester $I, Example $example): void
    {
        $admin = $I->haveAdmin();
        $comment = $I->haveCommentWithStatus(Examples::commentStatus($example, 'commentStatus'), $admin);
        $columns = ['text', 'updated_at', 'updated_by', 'deleted_at', 'deleted_by'];
        $before = $I->grabCommentColumns($comment->id, $columns);
        $I->amOnPage('/login');
        $csrf = [RegressionTester::CSRF_PARAM => $I->grabCsrfToken(), 'text' => 'Текст гостя'];
        $I->stopFollowingRedirects();

        $I->send(Examples::string($example, 'method'), sprintf(Examples::string($example, 'url'), $comment->id), $csrf);

        $I->seeResponseCodeIs(302);
        $I->seeHttpHeader('Location', 'http://admin.comments.localhost/login');
        $I->assertSame($before, $I->grabCommentColumns($comment->id, $columns));
    }

    /**
     * Все маршруты админки, кроме JSON (REG-A-21). `commentStatus` — статус комментария, который маршрут изменил бы
     * у вошедшего админа: восстанавливают удалённый, остальное меняет активный. CSRF-токен гостя тест шлёт, чтобы
     * отказ был из-за входа, а не из-за CSRF.
     *
     * @return array<string, array{method: string, url: string, commentStatus: CommentStatus}>
     */
    private function adminRoutes(): array
    {
        return [
            'список' => ['method' => 'GET', 'url' => '/', 'commentStatus' => CommentStatus::Active],
            '«Удалённые»' => ['method' => 'GET', 'url' => '/?status=deleted', 'commentStatus' => CommentStatus::Active],
            'правка (GET)' => ['method' => 'GET', 'url' => '/comments/%d/edit', 'commentStatus' => CommentStatus::Active],
            'сохранение (POST)' => ['method' => 'POST', 'url' => '/comments/%d/edit', 'commentStatus' => CommentStatus::Active],
            'удаление (POST)' => ['method' => 'POST', 'url' => '/comments/%d/delete', 'commentStatus' => CommentStatus::Active],
            'восстановление (POST)' => ['method' => 'POST', 'url' => '/comments/%d/restore', 'commentStatus' => CommentStatus::Deleted],
        ];
    }

    /**
     * REG-A-21: без входа JSON-эндпоинт → 401, а не редирект
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('jsonEndpoints')]
    public function guestGetsUnauthorizedOnJson(RegressionTester $I, Example $example): void
    {
        $I->stopFollowingRedirects();

        $I->send(Examples::string($example, 'method'), Examples::string($example, 'url'));

        $I->seeResponseCodeIs(401);
        $I->seeResponseContainsJson(['error' => 'unauthorized']);
        $I->dontSeeHttpHeader('Location');
    }

    /**
     * JSON-эндпоинты админки.
     *
     * @return array<string, array{method: string, url: string}>
     */
    private function jsonEndpoints(): array
    {
        return [
            'токен WS' => ['method' => 'POST', 'url' => '/api/ws-token'],
            'список' => ['method' => 'GET', 'url' => '/api/comments'],
        ];
    }
}
