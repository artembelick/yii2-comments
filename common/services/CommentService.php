<?php

declare(strict_types=1);

namespace common\services;

use common\events\EventPublisherInterface;
use common\events\EventPublishException;
use common\forms\CommentForm;
use common\forms\CommentTextForm;
use common\models\Comment;
use common\models\User;
use common\repositories\CommentRepositoryInterface;
use Psr\Clock\ClockInterface;
use Yii;

/**
 * Сценарии над комментариями. Гость публикует комментарий сразу, без одобрения; админ правит текст,
 * удаляет и восстанавливает. О каждом изменении WS-сервер узнаёт событием в comments.events; сбой
 * публикации действие не отменяет. Повторное удаление или восстановление ничего не меняет и событий не даёт.
 */
final readonly class CommentService
{
    public function __construct(
        private CommentRepositoryInterface $comments,
        private ClockInterface $clock,
        private EventPublisherInterface $events,
        private CommentSerializer $serializer,
    ) {}

    /** Сохраняет комментарий из провалидированной формы: нормализованные имя и текст, время из часов. */
    public function create(CommentForm $form): Comment
    {
        $comment = $this->comments->create($form->getNormalizedName(), $form->getNormalizedText(), $this->clock->now());
        $this->publish('comment.created', $comment);

        return $comment;
    }

    /**
     * Новый текст от имени админа. Ввод, который после нормализации совпал с текущим текстом, правкой не считается.
     *
     * @throws CommentNotFoundException
     * @throws CommentIsDeletedException удалённый не правят, в том числе если его удалили во время правки
     */
    public function updateText(int $id, CommentTextForm $form, User $admin): UpdateResult
    {
        $comment = $this->find($id);
        if ($comment->isDeleted()) {
            throw new CommentIsDeletedException($id);
        }

        if ($form->isSameAs($comment->text)) {
            return UpdateResult::Unchanged;
        }

        $text = $form->getNormalizedText();
        $now = $this->clock->now();
        if (!$this->comments->updateText($id, $text, $admin->id, $now)) {
            throw new CommentIsDeletedException($id);
        }

        $comment->text = $text;
        $comment->updated_at = $now->format(DATE_ATOM);
        $comment->updated_by = $admin->id;
        $comment->populateRelation('updatedByUser', $admin);
        $this->publish('comment.updated', $comment);

        return UpdateResult::Updated;
    }

    /**
     * Удаляет комментарий от имени админа. false — он уже удалён: удалившим остаётся первый админ.
     *
     * @throws CommentNotFoundException
     */
    public function delete(int $id, User $admin): bool
    {
        $comment = $this->find($id);
        $now = $this->clock->now();
        if (!$this->comments->markDeleted($id, $admin->id, $now)) {
            return false;
        }

        $comment->deleted_at = $now->format(DATE_ATOM);
        $comment->deleted_by = $admin->id;
        $comment->populateRelation('deletedByUser', $admin);
        $this->publish('comment.deleted', $comment);

        return true;
    }

    /**
     * Возвращает комментарий в ленту в прежнем виде. false — он уже активен.
     *
     * @throws CommentNotFoundException
     */
    public function restore(int $id): bool
    {
        $comment = $this->find($id);
        if (!$this->comments->markRestored($id)) {
            return false;
        }

        $comment->deleted_at = null;
        $comment->deleted_by = null;
        $comment->populateRelation('deletedByUser', null);
        $this->publish('comment.restored', $comment);

        return true;
    }

    /**
     * @throws CommentNotFoundException
     */
    private function find(int $id): Comment
    {
        return $this->comments->findById($id) ?? throw new CommentNotFoundException($id);
    }

    /** Комментарий уже сохранён: без события админки увидят его после перезагрузки, поэтому сбой только в лог. */
    private function publish(string $type, Comment $comment): void
    {
        try {
            $this->events->publish(EventPublisherInterface::COMMENTS, ['type' => $type, 'comment' => $this->serializer->toArray($comment)]);
        } catch (EventPublishException $eventPublishException) {
            Yii::error(
                sprintf('Событие %s для комментария %d не опубликовано: %s', $type, $comment->id, $eventPublishException->getMessage()),
                self::class,
            );
        }
    }
}
