<?php

declare(strict_types=1);

namespace common\repositories;

use common\models\Comment;

/** Страница комментариев: записи и всё, что нужно пагинации. Только для чтения. */
final readonly class CommentPage
{
    /**
     * @param list<Comment> $items
     */
    public function __construct(
        public array $items,
        public int $totalCount,
        public int $page,
        public int $pageCount,
    ) {}
}
