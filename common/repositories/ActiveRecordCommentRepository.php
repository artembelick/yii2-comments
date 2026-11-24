<?php

declare(strict_types=1);

namespace common\repositories;

use common\models\Comment;
use DateTimeImmutable;
use Override;

final class ActiveRecordCommentRepository implements CommentRepositoryInterface
{
    /** Метка времени для timestamptz с микросекундами и смещением. */
    private const string TIMESTAMP = 'Y-m-d H:i:s.uP';

    #[Override]
    public function create(string $author, string $text, DateTimeImmutable $now): Comment
    {
        $comment = new Comment();
        $comment->author = $author;
        $comment->text = $text;
        // С микросекундами: иначе комментарий, созданный в ту же секунду, что и соседние, встаёт в ленте ниже них.
        $comment->created_at = $now->format(self::TIMESTAMP);
        $comment->save(false);

        return $comment;
    }

    #[Override]
    public function findById(int $id): ?Comment
    {
        return Comment::findOne($id);
    }

    #[Override]
    public function page(CommentStatus $status, int $page, int $perPage): CommentPage
    {
        $query = match ($status) {
            CommentStatus::Active => Comment::find()->active(),
            CommentStatus::Deleted => Comment::find()->deleted(),
        };
        $totalCount = (int) $query->count();
        $pageCount = max(1, (int) ceil($totalCount / $perPage));
        $page = min(max($page, 1), $pageCount);

        // Аудит нужен каждой строке списка: админы подгружаются двумя запросами на страницу, а не по запросу на строку.
        /** @var list<Comment> $items */
        $items = $query->with(['updatedByUser', 'deletedByUser'])->newestFirst()->offset(($page - 1) * $perPage)->limit($perPage)->all();

        return new CommentPage($items, $totalCount, $page, $pageCount);
    }
}
