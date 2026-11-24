<?php

declare(strict_types=1);

namespace common\repositories;

use common\models\Comment;
use Override;

final class ActiveRecordCommentRepository implements CommentRepositoryInterface
{
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

        /** @var list<Comment> $items */
        $items = $query->newestFirst()->offset(($page - 1) * $perPage)->limit($perPage)->all();

        return new CommentPage($items, $totalCount, $page, $pageCount);
    }
}
