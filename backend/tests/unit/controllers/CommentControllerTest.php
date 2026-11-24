<?php

declare(strict_types=1);

namespace backend\tests\unit\controllers;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\models\User;
use common\repositories\CommentPage;
use common\repositories\CommentRepositoryInterface;
use common\repositories\CommentStatus;
use PHPUnit\Framework\Attributes\TestDox;
use Yii;
use yii\web\BadRequestHttpException;

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
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->expects(self::never())->method('page');
        Yii::$container->set(CommentRepositoryInterface::class, $comments);
        Yii::$app->user->setIdentity(new User());

        $this->expectException(BadRequestHttpException::class);

        Yii::$app->runAction('comment/index', ['status' => 'foo']);
    }
}
