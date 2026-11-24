<?php

declare(strict_types=1);

namespace common\repositories;

use common\models\Comment;
use DateTimeImmutable;

interface CommentRepositoryInterface
{
    /** Новый активный комментарий с датой создания $now. */
    public function create(string $author, string $text, DateTimeImmutable $now): Comment;

    /** Комментарий с любым статусом; несуществующий — null. */
    public function findById(int $id): ?Comment;

    /**
     * Страница комментариев со статусом $status, новые сверху. Номер страницы приводится в диапазон:
     * за пределами — последняя, 0 и меньше — первая. В пустой выборке одна пустая страница.
     */
    public function page(CommentStatus $status, int $page, int $perPage): CommentPage;
}
