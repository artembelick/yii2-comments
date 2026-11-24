<?php

declare(strict_types=1);

namespace common\services;

use common\forms\CommentForm;
use common\models\Comment;
use common\repositories\CommentRepositoryInterface;
use Psr\Clock\ClockInterface;

/** Сценарии над комментариями. Гость публикует комментарий сразу, без одобрения. */
final readonly class CommentService
{
    public function __construct(
        private CommentRepositoryInterface $comments,
        private ClockInterface $clock,
    ) {}

    /** Сохраняет комментарий из провалидированной формы: нормализованные имя и текст, время из часов. */
    public function create(CommentForm $form): Comment
    {
        return $this->comments->create($form->getNormalizedName(), $form->getNormalizedText(), $this->clock->now());
    }
}
