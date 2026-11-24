<?php

declare(strict_types=1);

namespace common\services;

use common\models\Comment;
use common\repositories\CommentStatus;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Комментарий для JSON-списка админки и событий WS (docs/test-plans/regression.md, «WS-контракты»).
 * Даты в ISO 8601 UTC, админы аудита — логинами.
 *
 * @phpstan-type SerializedComment array{
 *     id: int,
 *     author: string,
 *     text: string,
 *     status: string,
 *     createdAt: string,
 *     updatedAt: string|null,
 *     updatedBy: string|null,
 *     deletedBy: string|null,
 * }
 */
final readonly class CommentSerializer
{
    private const string ISO_UTC = 'Y-m-d\TH:i:s\Z';

    /**
     * @return SerializedComment
     */
    public function toArray(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'author' => $comment->author,
            'text' => $comment->text,
            'status' => ($comment->isDeleted() ? CommentStatus::Deleted : CommentStatus::Active)->value,
            'createdAt' => $this->isoUtc($comment->created_at),
            'updatedAt' => $comment->updated_at === null ? null : $this->isoUtc($comment->updated_at),
            'updatedBy' => $comment->updatedByUser?->username,
            'deletedBy' => $comment->deletedByUser?->username,
        ];
    }

    /**
     * Дата в формате JSON и событий. Тот же формат стоит в `data-created-at` строк списка админки: клиент сравнивает
     * его с `createdAt` события как строку, поэтому формат один на всех.
     */
    public function isoUtc(string $timestamp): string
    {
        return (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('UTC'))->format(self::ISO_UTC);
    }
}
