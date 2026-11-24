<?php

declare(strict_types=1);

namespace common\tests\unit\services;

use Codeception\Test\Unit;
use common\events\EventPublisherInterface;
use common\events\EventPublishException;
use common\forms\CommentForm;
use common\models\Comment;
use common\repositories\CommentRepositoryInterface;
use common\services\CommentSerializer;
use common\services\CommentService;
use common\tests\_support\RecordingLogger;
use common\text\CommentTextNormalizer;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Clock\MockClock;
use Yii;
use yii\log\Logger;

final class CommentServiceTest extends Unit
{
    private const string NOW = '2026-11-24 10:15:30+00:00';

    #[TestDox('UNIT-SVC-01: create передаёт в репозиторий нормализованные имя и текст и время из часов')]
    public function testCreateStoresNormalizedCommentAtClockTime(): void
    {
        $form = $this->form('  Ян  ', "\r\nпервая\r\nвторая \n");
        $created = $this->comment();
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->expects(self::once())->method('create')
            ->with('Ян', "первая\nвторая", new DateTimeImmutable(self::NOW))
            ->willReturn($created);

        $comment = $this->service($comments, self::createStub(EventPublisherInterface::class))->create($form);

        self::assertSame($created, $comment);
    }

    #[TestDox('UNIT-SVC-01: create публикует comment.created с сериализованным комментарием')]
    public function testCreatePublishesCreatedEvent(): void
    {
        $created = $this->comment();
        $comments = self::createStub(CommentRepositoryInterface::class);
        $comments->method('create')->willReturn($created);
        $events = $this->createMock(EventPublisherInterface::class);
        $events->expects(self::once())->method('publish')->with('comments.events', [
            'type' => 'comment.created',
            'comment' => (new CommentSerializer())->toArray($created),
        ]);

        $this->service($comments, $events)->create($this->form('Ян', 'Комментарий'));
    }

    #[TestDox('UNIT-SVC-02: публикация упала → комментарий всё равно возвращается, ошибка с id комментария в логе')]
    public function testCreateSurvivesPublishFailure(): void
    {
        $created = $this->comment();
        $comments = self::createStub(CommentRepositoryInterface::class);
        $comments->method('create')->willReturn($created);
        $events = self::createStub(EventPublisherInterface::class);
        $events->method('publish')->willThrowException(new EventPublishException('Событие в comments.events не опубликовано'));
        $log = new RecordingLogger();
        Yii::setLogger($log);

        $comment = $this->service($comments, $events)->create($this->form('Ян', 'Комментарий'));

        self::assertSame($created, $comment);
        self::assertSame(
            [[Logger::LEVEL_ERROR, 'Событие comment.created для комментария 42 не опубликовано: Событие в comments.events не опубликовано']],
            $log->messagesOf(CommentService::class),
        );
    }

    private function service(CommentRepositoryInterface $comments, EventPublisherInterface $events): CommentService
    {
        return new CommentService($comments, new MockClock(self::NOW), $events, new CommentSerializer());
    }

    private function form(string $name, string $text): CommentForm
    {
        return new CommentForm(new CommentTextNormalizer(), ['name' => $name, 'text' => $text]);
    }

    /** Сохранённый комментарий без правок: связи аудита пусты, в БД за ними ходить не нужно. */
    private function comment(): Comment
    {
        $comment = new Comment();
        $comment->setAttributes(['id' => 42, 'author' => 'Ян', 'text' => 'Комментарий', 'created_at' => '2026-11-24 10:15:30+00'], false);
        $comment->populateRelation('updatedByUser', null);
        $comment->populateRelation('deletedByUser', null);

        return $comment;
    }
}
