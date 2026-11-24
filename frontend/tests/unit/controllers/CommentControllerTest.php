<?php

declare(strict_types=1);

namespace frontend\tests\unit\controllers;

use Codeception\Test\Unit;
use common\events\EventPublisherInterface;
use common\models\Comment;
use common\repositories\CommentPage;
use common\repositories\CommentRepositoryInterface;
use common\repositories\CommentStatus;
use common\security\RateLimiterInterface;
use common\security\RateLimiterUnavailableException;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Yii;
use yii\helpers\Html;
use yii\log\Logger;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

final class CommentControllerTest extends Unit
{
    private const string GUEST_IP = '10.0.0.7';

    private const string NOW = '2026-11-24 10:15:30+00:00';

    #[Override]
    protected function _before(): void
    {
        $_SERVER['REMOTE_ADDR'] = self::GUEST_IP;
        // Публикацию событий проверяет UNIT-SVC-01, здесь она не должна уходить в Redis.
        Yii::$container->set(EventPublisherInterface::class, self::createStub(EventPublisherInterface::class));
    }

    #[Override]
    protected function _after(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        Yii::setLogger(null);
    }

    #[TestDox('UNIT-FC-01: index просит у репозитория страницу активных по 20 и показывает её с пустой формой')]
    public function testIndexShowsPageOfActiveWithEmptyForm(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->expects(self::once())->method('page')->with(CommentStatus::Active, 2, 20)
            ->willReturn(new CommentPage([$this->comment('Ян', 'Первый комментарий')], 21, 2, 2));
        Yii::$container->set(CommentRepositoryInterface::class, $comments);

        $page = Yii::$app->runAction('comment/index', ['page' => '2']);

        self::assertSame(200, Yii::$app->response->statusCode);
        self::assertIsString($page);
        self::assertStringContainsString('Первый комментарий', $page);
        self::assertStringContainsString('name="name" value=""', $page);
        self::assertStringContainsString('name="text" rows="4"></textarea>', $page);
    }

    #[TestDox('UNIT-FC-02: create сначала засчитывает попытку в лимит, потом валидирует: невалидный POST тратит лимит, а при исчерпанном лимите получает 429, а не 422')]
    public function testEveryPostSpendsLimitBeforeValidation(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects(self::once())->method('hit')->with('comment:' . self::GUEST_IP, 5, 60)->willReturn(false);
        Yii::$container->set(RateLimiterInterface::class, $limiter);
        Yii::$container->set(CommentRepositoryInterface::class, $this->feedRepository());
        $this->post(['name' => '', 'text' => '']);

        Yii::$app->runAction('comment/create');

        self::assertSame(429, Yii::$app->response->statusCode);
    }

    #[TestDox('UNIT-FC-03: валидный create сохраняет комментарий, ставит флэш comment.created и редиректит 302 на /')]
    public function testValidPostPublishesAndRedirects(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->expects(self::once())->method('create')
            ->with('Ян', 'Комментарий', new DateTimeImmutable(self::NOW))
            ->willReturn($this->comment('Ян', 'Комментарий'));
        Yii::$container->set(CommentRepositoryInterface::class, $comments);
        Yii::$container->set(ClockInterface::class, new MockClock(self::NOW));
        Yii::$container->set(RateLimiterInterface::class, $this->limiterAllowing(true));
        $this->post(['name' => ' Ян ', 'text' => "Комментарий\r\n"]);

        $response = Yii::$app->runAction('comment/create');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(
            [302, 'http://comments.localhost/', Yii::t('app', 'comment.created')],
            [$response->statusCode, $response->headers->get('Location'), Yii::$app->session->getFlash('success')],
        );
    }

    #[TestDox('UNIT-FC-04: невалидный create → 422, лента с формой, ошибками и введёнными значениями, сервис не вызывается')]
    public function testInvalidPostShowsFormWithErrors(): void
    {
        Yii::$container->set(CommentRepositoryInterface::class, $this->feedRepository());
        Yii::$container->set(RateLimiterInterface::class, $this->limiterAllowing(true));
        $this->post(['name' => 'Я', 'text' => 'Введённый текст']);

        $page = Yii::$app->runAction('comment/create');

        self::assertSame(422, Yii::$app->response->statusCode);
        self::assertIsString($page);
        self::assertStringContainsString(Html::encode(Yii::t('app', 'name.too_short')), $page);
        self::assertStringContainsString('name="name" value="Я"', $page);
        self::assertStringContainsString('>Введённый текст</textarea>', $page);
    }

    #[TestDox('UNIT-FC-05: лимит исчерпан → 429, сообщение comment.rate_limited и введённые значения, сервис не вызывается')]
    public function testRateLimitedPostIsRejected(): void
    {
        Yii::$container->set(CommentRepositoryInterface::class, $this->feedRepository());
        Yii::$container->set(RateLimiterInterface::class, $this->limiterAllowing(false));
        $this->post(['name' => 'Ян', 'text' => 'Валидный текст']);

        $page = Yii::$app->runAction('comment/create');

        self::assertSame(429, Yii::$app->response->statusCode);
        self::assertIsString($page);
        self::assertStringContainsString(Html::encode(Yii::t('app', 'comment.rate_limited')), $page);
        self::assertStringContainsString('name="name" value="Ян"', $page);
        self::assertStringContainsString('>Валидный текст</textarea>', $page);
    }

    #[TestDox('UNIT-FC-06: счётчики лимита недоступны → комментарий публикуется как обычно, ошибка пишется в лог')]
    public function testUnavailableLimiterLetsCommentThrough(): void
    {
        $limiter = self::createStub(RateLimiterInterface::class);
        $limiter->method('hit')->willThrowException(new RateLimiterUnavailableException('redis down'));
        Yii::$container->set(RateLimiterInterface::class, $limiter);
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->expects(self::once())->method('create')->willReturn($this->comment('Ян', 'Комментарий'));
        Yii::$container->set(CommentRepositoryInterface::class, $comments);
        $logger = new Logger();
        Yii::setLogger($logger);
        $this->post(['name' => 'Ян', 'text' => 'Комментарий']);

        $response = Yii::$app->runAction('comment/create');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(302, $response->statusCode);
        self::assertSame(
            ['Отправка без лимита: счётчики недоступны. redis down'],
            array_column(array_filter($logger->messages, static fn(array $message): bool => $message[1] === Logger::LEVEL_ERROR), 0),
        );
    }

    #[TestDox('UNIT-FC-07: create принимает только POST')]
    public function testCreateRejectsGet(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects(self::never())->method('hit');
        Yii::$container->set(RateLimiterInterface::class, $limiter);
        Yii::$container->set(CommentRepositoryInterface::class, $this->feedRepository());

        $this->expectException(MethodNotAllowedHttpException::class);

        Yii::$app->runAction('comment/create');
    }

    private function limiterAllowing(bool $allowed): RateLimiterInterface
    {
        $limiter = self::createStub(RateLimiterInterface::class);
        $limiter->method('hit')->willReturn($allowed);

        return $limiter;
    }

    private function feedRepository(): CommentRepositoryInterface
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->method('page')->willReturn(new CommentPage([], 0, 1, 1));
        $comments->expects(self::never())->method('create');

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

    private function comment(string $author, string $text): Comment
    {
        $comment = new Comment();
        $comment->setAttributes(['id' => 1, 'author' => $author, 'text' => $text, 'created_at' => '2026-11-24 10:00:00+00'], false);
        $comment->populateRelation('updatedByUser', null);
        $comment->populateRelation('deletedByUser', null);

        return $comment;
    }
}
