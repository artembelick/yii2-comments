<?php

declare(strict_types=1);

namespace common\tests\unit\services;

use Closure;
use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\events\EventPublisherInterface;
use common\events\EventPublishException;
use common\forms\CommentForm;
use common\forms\CommentTextForm;
use common\models\Comment;
use common\models\User;
use common\repositories\CommentRepositoryInterface;
use common\services\CommentIsDeletedException;
use common\services\CommentNotFoundException;
use common\services\CommentSerializer;
use common\services\CommentService;
use common\services\UpdateResult;
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

    /** Комментарий из comment() после сериализации: активный, без правок. */
    private const array SERIALIZED = [
        'id' => 42,
        'author' => 'Ян',
        'text' => 'Комментарий',
        'status' => 'active',
        'createdAt' => '2026-11-24T10:15:30Z',
        'updatedAt' => null,
        'updatedBy' => null,
        'deletedBy' => null,
    ];

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

    #[TestDox('UNIT-SVC-03: updateText несуществующего → CommentNotFoundException')]
    public function testUpdateTextOfUnknownCommentFails(): void
    {
        $comments = self::createStub(CommentRepositoryInterface::class);
        $comments->method('findById')->willReturn(null);

        $this->expectException(CommentNotFoundException::class);

        $this->service($comments, self::createStub(EventPublisherInterface::class))->updateText(42, $this->textForm('Новый текст'), $this->admin());
    }

    #[TestDox('UNIT-SVC-03: updateText удалённого → CommentIsDeletedException, репозиторий текст не меняет')]
    public function testUpdateTextOfDeletedCommentFails(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->method('findById')->willReturn($this->comment(['deleted_at' => '2026-11-24 10:00:00+00', 'deleted_by' => 8]));
        $comments->expects(self::never())->method('updateText');

        $this->expectException(CommentIsDeletedException::class);

        $this->service($comments, self::createStub(EventPublisherInterface::class))->updateText(42, $this->textForm('Новый текст'), $this->admin());
    }

    #[TestDox('UNIT-SVC-04: isSameAs вернул true → Unchanged, репозиторий не вызывается, событий нет')]
    public function testUpdateTextWithSameTextIsUnchanged(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->method('findById')->willReturn($this->comment());
        $comments->expects(self::never())->method('updateText');
        $events = $this->createMock(EventPublisherInterface::class);
        $events->expects(self::never())->method('publish');

        $result = $this->service($comments, $events)->updateText(42, $this->textForm("  Комментарий\r\n"), $this->admin());

        self::assertSame(UpdateResult::Unchanged, $result);
    }

    #[TestDox('UNIT-SVC-05: новый текст → updateText репозитория с нормализованным текстом, админом и временем из часов → Updated')]
    public function testUpdateTextStoresNormalizedText(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->method('findById')->with(42)->willReturn($this->comment());
        $comments->expects(self::once())->method('updateText')
            ->with(42, "Новый\nтекст", 7, new DateTimeImmutable(self::NOW))
            ->willReturn(true);

        $result = $this->service($comments, self::createStub(EventPublisherInterface::class))
            ->updateText(42, $this->textForm(" Новый\r\nтекст "), $this->admin());

        self::assertSame(UpdateResult::Updated, $result);
    }

    #[TestDox('UNIT-SVC-05: новый текст → событие comment.updated с новым текстом и updatedBy')]
    public function testUpdateTextPublishesUpdatedEvent(): void
    {
        $comments = self::createStub(CommentRepositoryInterface::class);
        $comments->method('findById')->willReturn($this->comment());
        $comments->method('updateText')->willReturn(true);
        $events = $this->createMock(EventPublisherInterface::class);
        $events->expects(self::once())->method('publish')->with('comments.events', [
            'type' => 'comment.updated',
            'comment' => [...self::SERIALIZED, 'text' => 'Новый текст', 'updatedAt' => '2026-11-24T10:15:30Z', 'updatedBy' => 'anna'],
        ]);

        $this->service($comments, $events)->updateText(42, $this->textForm('Новый текст'), $this->admin());
    }

    #[TestDox('UNIT-SVC-06: репозиторий вернул false (удалили параллельно) → CommentIsDeletedException, событий нет')]
    public function testUpdateTextLosingRaceToDeletionFails(): void
    {
        $comments = self::createStub(CommentRepositoryInterface::class);
        $comments->method('findById')->willReturn($this->comment());
        $comments->method('updateText')->willReturn(false);
        $events = $this->createMock(EventPublisherInterface::class);
        $events->expects(self::never())->method('publish');

        $this->expectException(CommentIsDeletedException::class);

        $this->service($comments, $events)->updateText(42, $this->textForm('Новый текст'), $this->admin());
    }

    #[TestDox('UNIT-SVC-07: delete — markDeleted с админом и временем из часов вернул true → результат true')]
    public function testDeleteMarksCommentDeleted(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->method('findById')->with(42)->willReturn($this->comment());
        $comments->expects(self::once())->method('markDeleted')->with(42, 7, new DateTimeImmutable(self::NOW))->willReturn(true);

        $deleted = $this->service($comments, self::createStub(EventPublisherInterface::class))->delete(42, $this->admin());

        self::assertTrue($deleted);
    }

    #[TestDox('UNIT-SVC-07: delete — markDeleted вернул true → событие comment.deleted с deletedBy')]
    public function testDeletePublishesDeletedEvent(): void
    {
        $comments = self::createStub(CommentRepositoryInterface::class);
        $comments->method('findById')->willReturn($this->comment());
        $comments->method('markDeleted')->willReturn(true);
        $events = $this->createMock(EventPublisherInterface::class);
        $events->expects(self::once())->method('publish')->with('comments.events', [
            'type' => 'comment.deleted',
            'comment' => [...self::SERIALIZED, 'status' => 'deleted', 'deletedBy' => 'anna'],
        ]);

        $this->service($comments, $events)->delete(42, $this->admin());
    }

    #[TestDox('UNIT-SVC-07: delete — markDeleted вернул false (уже удалён) → результат false, событий нет')]
    public function testDeleteOfDeletedCommentIsNoop(): void
    {
        $comments = self::createStub(CommentRepositoryInterface::class);
        $comments->method('findById')->willReturn($this->comment(['deleted_at' => '2026-11-24 10:00:00+00', 'deleted_by' => 8]));
        $comments->method('markDeleted')->willReturn(false);
        $events = $this->createMock(EventPublisherInterface::class);
        $events->expects(self::never())->method('publish');

        $deleted = $this->service($comments, $events)->delete(42, $this->admin());

        self::assertFalse($deleted);
    }

    #[TestDox('UNIT-SVC-08: restore — markRestored вернул true → результат true')]
    public function testRestoreMarksCommentRestored(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->method('findById')->with(42)->willReturn($this->comment(['deleted_at' => '2026-11-24 10:00:00+00', 'deleted_by' => 8]));
        $comments->expects(self::once())->method('markRestored')->with(42)->willReturn(true);

        $restored = $this->service($comments, self::createStub(EventPublisherInterface::class))->restore(42);

        self::assertTrue($restored);
    }

    #[TestDox('UNIT-SVC-08: restore — markRestored вернул true → событие comment.restored без deletedBy')]
    public function testRestorePublishesRestoredEvent(): void
    {
        $comments = self::createStub(CommentRepositoryInterface::class);
        $comments->method('findById')->willReturn($this->comment(['deleted_at' => '2026-11-24 10:00:00+00', 'deleted_by' => 8]));
        $comments->method('markRestored')->willReturn(true);
        $events = $this->createMock(EventPublisherInterface::class);
        $events->expects(self::once())->method('publish')->with('comments.events', ['type' => 'comment.restored', 'comment' => self::SERIALIZED]);

        $this->service($comments, $events)->restore(42);
    }

    #[TestDox('UNIT-SVC-08: restore — markRestored вернул false (уже активен) → результат false, событий нет')]
    public function testRestoreOfActiveCommentIsNoop(): void
    {
        $comments = self::createStub(CommentRepositoryInterface::class);
        $comments->method('findById')->willReturn($this->comment());
        $comments->method('markRestored')->willReturn(false);
        $events = $this->createMock(EventPublisherInterface::class);
        $events->expects(self::never())->method('publish');

        $restored = $this->service($comments, $events)->restore(42);

        self::assertFalse($restored);
    }

    /**
     * @param Closure(CommentService, User): mixed $action
     */
    #[TestDox('UNIT-SVC-09: delete и restore несуществующего → CommentNotFoundException')]
    #[DataProvider('deletionActions')]
    public function testDeletionActionsOnUnknownCommentFail(Closure $action): void
    {
        $comments = self::createStub(CommentRepositoryInterface::class);
        $comments->method('findById')->willReturn(null);
        $service = $this->service($comments, self::createStub(EventPublisherInterface::class));

        $this->expectException(CommentNotFoundException::class);

        $action($service, $this->admin());
    }

    /**
     * @return array<string, array{Closure(CommentService, User): mixed}>
     */
    public static function deletionActions(): array
    {
        return [
            'delete' => [static fn(CommentService $service, User $admin): bool => $service->delete(42, $admin)],
            'restore' => [static fn(CommentService $service, User $admin): bool => $service->restore(42)],
        ];
    }

    /**
     * @param Closure(CommentService, User): mixed $action
     * @param array<string, mixed> $deletion
     */
    #[TestDox('UNIT-SVC-10: публикация упала → результат действия прежний, ошибка в логе')]
    #[DataProvider('publishingActions')]
    public function testActionSurvivesPublishFailure(Closure $action, array $deletion, mixed $expected, string $type): void
    {
        $comments = self::createStub(CommentRepositoryInterface::class);
        $comments->method('findById')->willReturn($this->comment($deletion));
        $comments->method('updateText')->willReturn(true);
        $comments->method('markDeleted')->willReturn(true);
        $comments->method('markRestored')->willReturn(true);
        $events = self::createStub(EventPublisherInterface::class);
        $events->method('publish')->willThrowException(new EventPublishException('Событие в comments.events не опубликовано'));
        $log = new RecordingLogger();
        Yii::setLogger($log);

        $result = $action($this->service($comments, $events), $this->admin());

        self::assertSame($expected, $result);
        self::assertSame(
            [[Logger::LEVEL_ERROR, sprintf('Событие %s для комментария 42 не опубликовано: Событие в comments.events не опубликовано', $type)]],
            $log->messagesOf(CommentService::class),
        );
    }

    /**
     * @return array<string, array{Closure(CommentService, User): mixed, array<string, mixed>, mixed, string}>
     */
    public static function publishingActions(): array
    {
        $deleted = ['deleted_at' => '2026-11-24 10:00:00+00', 'deleted_by' => 8];
        $newText = new CommentTextForm(new CommentTextNormalizer(), ['text' => 'Новый текст']);

        return [
            'updateText → Updated' => [
                static fn(CommentService $service, User $admin): UpdateResult => $service->updateText(42, $newText, $admin),
                [],
                UpdateResult::Updated,
                'comment.updated',
            ],
            'delete → true' => [static fn(CommentService $service, User $admin): bool => $service->delete(42, $admin), [], true, 'comment.deleted'],
            'restore → true' => [static fn(CommentService $service, User $admin): bool => $service->restore(42), $deleted, true, 'comment.restored'],
        ];
    }

    private function service(CommentRepositoryInterface $comments, EventPublisherInterface $events): CommentService
    {
        return new CommentService($comments, new MockClock(self::NOW), $events, new CommentSerializer());
    }

    private function form(string $name, string $text): CommentForm
    {
        return new CommentForm(new CommentTextNormalizer(), ['name' => $name, 'text' => $text]);
    }

    private function textForm(string $text): CommentTextForm
    {
        return new CommentTextForm(new CommentTextNormalizer(), ['text' => $text]);
    }

    /** Админ anna с id 7: от его имени идут правка, удаление и восстановление. */
    private function admin(): User
    {
        $admin = new User();
        $admin->setAttributes(['id' => 7, 'username' => 'anna'], false);

        return $admin;
    }

    /**
     * Сохранённый комментарий без правок, $deletion (deleted_at, deleted_by) делает его удалённым. Связи аудита
     * пусты, в БД за ними ходить не нужно: удалившего в событии ставит сам сервис.
     *
     * @param array<string, mixed> $deletion
     */
    private function comment(array $deletion = []): Comment
    {
        $comment = new Comment();
        $comment->setAttributes(['id' => 42, 'author' => 'Ян', 'text' => 'Комментарий', 'created_at' => '2026-11-24 10:15:30+00', ...$deletion], false);
        $comment->populateRelation('updatedByUser', null);
        $comment->populateRelation('deletedByUser', null);

        return $comment;
    }
}
