<?php

declare(strict_types=1);

namespace common\services;

use common\events\EventPublisherInterface;
use common\events\EventPublishException;
use common\forms\CommentForm;
use common\models\Comment;
use common\repositories\CommentRepositoryInterface;
use Psr\Clock\ClockInterface;
use Yii;

/**
 * Сценарии над комментариями. Гость публикует комментарий сразу, без одобрения.
 * О каждом изменении WS-сервер узнаёт событием в comments.events; сбой публикации действие не отменяет.
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
