<?php

declare(strict_types=1);

namespace backend\tests\unit\controllers;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\events\EventPublisherInterface;
use common\models\Comment;
use common\models\User;
use common\repositories\CommentPage;
use common\repositories\CommentRepositoryInterface;
use common\repositories\CommentStatus;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use Yii;
use yii\helpers\Html;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
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

    #[TestDox('UNIT-BC-02: update через GET для активного → форма с текущим текстом')]
    public function testUpdateFormShowsCurrentText(): void
    {
        $this->signIn($this->repositoryFinding($this->comment(['text' => 'Текущий текст'])));

        $page = Yii::$app->runAction('comment/update', ['id' => '42']);

        self::assertSame(200, Yii::$app->response->statusCode);
        self::assertIsString($page);
        self::assertStringContainsString('>Текущий текст</textarea>', $page);
    }

    #[TestDox('UNIT-BC-02: update через GET для удалённого → 409 с admin.edit_deleted')]
    public function testUpdateFormOfDeletedIsConflict(): void
    {
        $this->signIn($this->repositoryFinding($this->comment(['deleted_at' => '2026-11-24 11:00:00+00', 'deleted_by' => 8])));

        $this->expectExceptionObject(new ConflictHttpException(Yii::t('app', 'admin.edit_deleted')));

        Yii::$app->runAction('comment/update', ['id' => '42']);
    }

    #[TestDox('UNIT-BC-02: update через GET для несуществующего → 404')]
    public function testUpdateFormOfUnknownIsNotFound(): void
    {
        $this->signIn($this->repositoryFinding(null));

        $this->expectException(NotFoundHttpException::class);

        Yii::$app->runAction('comment/update', ['id' => '42']);
    }

    #[TestDox('UNIT-BC-03: update через POST: сохранено или без изменений → флэш и редирект в список')]
    #[DataProvider('savedUpdates')]
    public function testUpdateRedirectsWithFlash(string $text, string $expectedType, string $expectedFlash): void
    {
        $comments = $this->repositoryFinding($this->comment(['text' => 'Текущий текст']));
        $comments->method('updateText')->willReturn(true);
        $this->signIn($comments);
        $this->post(['text' => $text]);

        $response = Yii::$app->runAction('comment/update', ['id' => '42']);

        self::assertSame(
            [302, 'http://admin.comments.localhost/', [$expectedType => Yii::t('app', $expectedFlash)]],
            $this->redirectWithFlashes($response),
        );
    }

    /**
     * Флэш об изменении — success, «ничего не изменилось» — info.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function savedUpdates(): array
    {
        return [
            'Updated → admin.updated' => ['Новый текст', 'success', 'admin.updated'],
            'Unchanged → admin.unchanged' => [" Текущий текст\r\n", 'info', 'admin.unchanged'],
        ];
    }

    #[TestDox('UNIT-BC-03: update через POST невалидно → 422 и форма с ошибкой и введённым текстом')]
    public function testInvalidUpdateShowsForm(): void
    {
        $comments = $this->repositoryFinding($this->comment());
        $comments->expects(self::never())->method('updateText');
        $this->signIn($comments);
        $this->post(['text' => str_repeat('ж', 2001)]);

        $page = Yii::$app->runAction('comment/update', ['id' => '42']);

        self::assertSame(422, Yii::$app->response->statusCode);
        self::assertIsString($page);
        self::assertStringContainsString(Html::encode(Yii::t('app', 'text.too_long')), $page);
        self::assertStringContainsString('>' . str_repeat('ж', 2001) . '</textarea>', $page);
    }

    #[TestDox('UNIT-BC-03: update через POST без поля text → 422 и ошибка text.required, а не «Изменений нет»')]
    public function testUpdateWithoutTextIsInvalid(): void
    {
        $comments = $this->repositoryFinding($this->comment(['text' => 'Текущий текст']));
        $comments->expects(self::never())->method('updateText');
        $this->signIn($comments);
        $this->post(['name' => 'Подменённое имя']);

        $page = Yii::$app->runAction('comment/update', ['id' => '42']);

        self::assertSame(422, Yii::$app->response->statusCode);
        self::assertIsString($page);
        self::assertStringContainsString(Html::encode(Yii::t('app', 'text.required')), $page);
    }

    #[TestDox('UNIT-BC-03: update через POST, комментарий удалили параллельно (CommentIsDeletedException) → 409')]
    public function testUpdateLosingRaceToDeletionIsConflict(): void
    {
        $comments = $this->repositoryFinding($this->comment());
        $comments->method('updateText')->willReturn(false);
        $this->signIn($comments);
        $this->post(['text' => 'Новый текст']);

        $this->expectExceptionObject(new ConflictHttpException(Yii::t('app', 'admin.edit_deleted')));

        Yii::$app->runAction('comment/update', ['id' => '42']);
    }

    #[TestDox('UNIT-BC-03: update через POST, сервис не нашёл комментарий (CommentNotFoundException) → 404')]
    public function testUpdateOfVanishedCommentIsNotFound(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->method('findById')->with(42)->willReturnOnConsecutiveCalls($this->comment(), null);
        $comments->expects(self::never())->method('updateText');
        $this->signIn($comments);
        $this->post(['text' => 'Новый текст']);

        $this->expectExceptionObject(new NotFoundHttpException());

        Yii::$app->runAction('comment/update', ['id' => '42']);
    }

    #[TestDox('UNIT-BC-04: delete → флэш результата и редирект в список с прежними вкладкой и страницей')]
    #[DataProvider('deleteOutcomes')]
    public function testDeleteRedirectsBackWithFlash(bool $changed, string $expectedType, string $expectedFlash): void
    {
        $comments = $this->repositoryFinding($this->comment());
        $comments->method('markDeleted')->willReturn($changed);
        $this->signIn($comments);
        $this->post(['status' => 'deleted', 'page' => '3']);

        $response = Yii::$app->runAction('comment/delete', ['id' => '42']);

        self::assertSame(
            [302, 'http://admin.comments.localhost/?status=deleted&page=3', [$expectedType => Yii::t('app', $expectedFlash)]],
            $this->redirectWithFlashes($response),
        );
    }

    /**
     * @return array<string, array{bool, string, string}>
     */
    public static function deleteOutcomes(): array
    {
        return [
            'удалил → admin.deleted' => [true, 'success', 'admin.deleted'],
            'уже удалён → admin.already_deleted' => [false, 'info', 'admin.already_deleted'],
        ];
    }

    #[TestDox('UNIT-BC-05: restore → флэш результата и редирект в список с прежними вкладкой и страницей')]
    #[DataProvider('restoreOutcomes')]
    public function testRestoreRedirectsBackWithFlash(bool $changed, string $expectedType, string $expectedFlash): void
    {
        $comments = $this->repositoryFinding($this->comment(['deleted_at' => '2026-11-24 11:00:00+00', 'deleted_by' => 8]));
        $comments->method('markRestored')->willReturn($changed);
        $this->signIn($comments);
        $this->post(['status' => 'deleted', 'page' => '3']);

        $response = Yii::$app->runAction('comment/restore', ['id' => '42']);

        self::assertSame(
            [302, 'http://admin.comments.localhost/?status=deleted&page=3', [$expectedType => Yii::t('app', $expectedFlash)]],
            $this->redirectWithFlashes($response),
        );
    }

    /**
     * @return array<string, array{bool, string, string}>
     */
    public static function restoreOutcomes(): array
    {
        return [
            'восстановил → admin.restored' => [true, 'success', 'admin.restored'],
            'уже активен → admin.already_active' => [false, 'info', 'admin.already_active'],
        ];
    }

    /**
     * @param array<string, string> $post
     */
    #[TestDox('UNIT-BC-04: вкладка или страница не пришли или неверны → редирект на первую страницу «Активных»')]
    #[DataProvider('brokenListViews')]
    public function testDeletionFallsBackToFirstActivePage(array $post): void
    {
        $comments = $this->repositoryFinding($this->comment());
        $comments->method('markDeleted')->willReturn(true);
        $this->signIn($comments);
        $this->post($post);

        $response = Yii::$app->runAction('comment/delete', ['id' => '42']);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('http://admin.comments.localhost/?status=active&page=1', $response->headers->get('Location'));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function brokenListViews(): array
    {
        return [
            'ничего не пришло' => [[]],
            'неизвестная вкладка, страница не число' => [['status' => 'foo', 'page' => 'abc']],
            'поля массивами' => [['status' => ['deleted'], 'page' => ['2']]],
        ];
    }

    #[TestDox('UNIT-BC-04: delete и restore (UNIT-BC-05) несуществующего → 404 без текста исключения сервиса')]
    #[DataProvider('deletionActions')]
    public function testDeletionActionOfUnknownIsNotFound(string $action): void
    {
        $this->signIn($this->repositoryFinding(null));
        $this->post([]);

        $this->expectExceptionObject(new NotFoundHttpException());

        Yii::$app->runAction('comment/' . $action, ['id' => '42']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function deletionActions(): array
    {
        return [
            'delete' => ['delete'],
            'restore' => ['restore'],
        ];
    }

    /**
     * @param array<string, string> $params
     */
    #[TestDox('UNIT-BC-07: гость на любом действии → редирект на логин, репозиторий не трогается')]
    #[DataProvider('allActions')]
    public function testEveryActionIsForSignedInOnly(string $action, array $params, string $method): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->expects(self::never())->method(self::anything());
        Yii::$container->set(CommentRepositoryInterface::class, $comments);
        Yii::$app->user->setIdentity(null);
        Yii::$app->request->headers->set('X-Http-Method-Override', $method);

        Yii::$app->runAction('comment/' . $action, $params);

        // Фильтр доступа не пускает в действие: редирект он ставит на ответ приложения.
        $response = Yii::$app->response;
        self::assertSame([302, 'http://admin.comments.localhost/login'], [$response->statusCode, $response->headers->get('Location')]);
    }

    /**
     * @return array<string, array{string, array<string, string>, string}>
     */
    public static function allActions(): array
    {
        return [
            'index' => ['index', [], 'GET'],
            'update через GET' => ['update', ['id' => '42'], 'GET'],
            'update через POST' => ['update', ['id' => '42'], 'POST'],
            'delete' => ['delete', ['id' => '42'], 'POST'],
            'restore' => ['restore', ['id' => '42'], 'POST'],
        ];
    }

    #[TestDox('UNIT-BC-07: VerbFilter — delete и restore только POST, update только GET и POST')]
    #[DataProvider('forbiddenVerbs')]
    public function testVerbFilterRejectsOtherMethods(string $action, string $method): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->expects(self::never())->method(self::anything());
        Yii::$container->set(CommentRepositoryInterface::class, $comments);
        Yii::$app->user->setIdentity($this->admin());
        Yii::$app->request->headers->set('X-Http-Method-Override', $method);

        $this->expectException(MethodNotAllowedHttpException::class);

        Yii::$app->runAction('comment/' . $action, ['id' => '42']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function forbiddenVerbs(): array
    {
        return [
            'delete через GET' => ['delete', 'GET'],
            'restore через GET' => ['restore', 'GET'],
            'update через PUT' => ['update', 'PUT'],
        ];
    }

    /**
     * Код ответа, адрес редиректа и все флэши сессии: вид (success, info) → текст.
     *
     * @return array{int, string|null, array<mixed>}
     */
    private function redirectWithFlashes(mixed $response): array
    {
        self::assertInstanceOf(Response::class, $response);

        return [$response->statusCode, $response->headers->get('Location'), Yii::$app->session->getAllFlashes()];
    }

    /** Вошедший админ anna, репозиторий — данный мок, публикация событий никуда не уходит. */
    private function signIn(CommentRepositoryInterface $comments): void
    {
        Yii::$container->set(CommentRepositoryInterface::class, $comments);
        Yii::$container->set(EventPublisherInterface::class, self::createStub(EventPublisherInterface::class));

        Yii::$app->user->setIdentity($this->admin());
    }

    /** Репозиторий, который по id 42 находит $comment. */
    private function repositoryFinding(?Comment $comment): CommentRepositoryInterface&MockObject
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->method('findById')->with(42)->willReturn($comment);

        return $comments;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(array $fields): void
    {
        Yii::$app->request->headers->set('X-Http-Method-Override', 'POST');
        Yii::$app->request->setBodyParams($fields);
    }

    private function admin(): User
    {
        $admin = new User();
        $admin->setAttributes(['id' => 7, 'username' => 'anna'], false);

        return $admin;
    }

    /**
     * Активный комментарий 42 без правок; $attributes меняют текст или делают его удалённым.
     *
     * @param array<string, mixed> $attributes
     */
    private function comment(array $attributes = []): Comment
    {
        $comment = new Comment();
        $comment->setAttributes(['id' => 42, 'author' => 'Ян', 'text' => 'Комментарий', 'created_at' => '2026-11-24 10:00:00+00', ...$attributes], false);
        $comment->populateRelation('updatedByUser', null);
        $comment->populateRelation('deletedByUser', null);

        return $comment;
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
