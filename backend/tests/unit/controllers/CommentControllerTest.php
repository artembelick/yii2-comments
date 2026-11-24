<?php

declare(strict_types=1);

namespace backend\tests\unit\controllers;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\models\Comment;
use common\models\User;
use common\repositories\CommentPage;
use common\repositories\CommentRepositoryInterface;
use common\repositories\CommentStatus;
use PHPUnit\Framework\Attributes\TestDox;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\Response;

final class CommentControllerTest extends Unit
{
    /**
     * @param array<string, string> $params
     */
    #[TestDox('UNIT-BC-01: index просит у репозитория страницу своей вкладки по 20 записей')]
    #[DataProvider('tabs')]
    public function testIndexRequestsPageOfTab(array $params, CommentStatus $expectedStatus, int $expectedPage): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->expects(self::once())->method('page')->with($expectedStatus, $expectedPage, 20)->willReturn(new CommentPage([], 0, 1, 1));
        Yii::$container->set(CommentRepositoryInterface::class, $comments);
        Yii::$app->user->setIdentity(new User());

        $page = Yii::$app->runAction('comment/index', $params);

        self::assertSame(200, Yii::$app->response->statusCode);
        self::assertIsString($page);
    }

    /**
     * @return array<string, array{array<string, string>, CommentStatus, int}>
     */
    public static function tabs(): array
    {
        return [
            'без параметров → «Активные», страница 1' => [[], CommentStatus::Active, 1],
            'status=active' => [['status' => 'active'], CommentStatus::Active, 1],
            'status=deleted, page=3' => [['status' => 'deleted', 'page' => '3'], CommentStatus::Deleted, 3],
            'page=abc → страница 0, репозиторий приведёт к первой' => [['page' => 'abc'], CommentStatus::Active, 0],
        ];
    }

    #[TestDox('UNIT-BC-01: status=foo → 400')]
    public function testUnknownStatusIsBadRequest(): void
    {
        Yii::$container->set(CommentRepositoryInterface::class, self::createStub(CommentRepositoryInterface::class));
        Yii::$app->user->setIdentity(new User());

        $this->expectException(BadRequestHttpException::class);

        Yii::$app->runAction('comment/index', ['status' => 'foo']);
    }

    #[TestDox('UNIT-BC-06: list → JSON со страницей вкладки по 20 записей: items, page, pageCount и totalCount')]
    public function testListReturnsPageAsJson(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->expects(self::once())->method('page')->with(CommentStatus::Deleted, 2, 20)
            ->willReturn(new CommentPage([$this->deletedComment(42, 'Ян')], 21, 2, 2));
        Yii::$container->set(CommentRepositoryInterface::class, $comments);
        Yii::$app->user->setIdentity(new User());

        $response = Yii::$app->runAction('comment/list', ['status' => 'deleted', 'page' => '2']);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame([200, Response::FORMAT_JSON], [$response->statusCode, $response->format]);
        self::assertSame([
            'items' => [[
                'id' => 42,
                'author' => 'Ян',
                'text' => 'Комментарий',
                'status' => 'deleted',
                'createdAt' => '2026-11-24T10:00:00Z',
                'updatedAt' => null,
                'updatedBy' => null,
                'deletedBy' => 'boris',
            ]],
            'page' => 2,
            'pageCount' => 2,
            'totalCount' => 21,
        ], $response->data);
    }

    #[TestDox('UNIT-BC-06: list с неверным status → 400 и JSON с error invalid_status')]
    public function testListRejectsUnknownStatus(): void
    {
        Yii::$container->set(CommentRepositoryInterface::class, self::createStub(CommentRepositoryInterface::class));
        Yii::$app->user->setIdentity(new User());

        $response = Yii::$app->runAction('comment/list', ['status' => 'foo']);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame([400, ['error' => 'invalid_status']], [$response->statusCode, $response->data]);
    }

    /** Комментарий вкладки «Удалённые»: удалил админ boris. */
    private function deletedComment(int $id, string $author): Comment
    {
        $remover = new User();
        $remover->username = 'boris';

        $comment = new Comment();
        $comment->setAttributes([
            'id' => $id,
            'author' => $author,
            'text' => 'Комментарий',
            'created_at' => '2026-11-24 10:00:00+00',
            'deleted_at' => '2026-11-24 11:00:00+00',
            'deleted_by' => 7,
        ], false);
        $comment->populateRelation('updatedByUser', null);
        $comment->populateRelation('deletedByUser', $remover);

        return $comment;
    }
}
