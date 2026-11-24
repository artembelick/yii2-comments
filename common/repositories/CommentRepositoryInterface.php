<?php

declare(strict_types=1);

namespace common\repositories;

interface CommentRepositoryInterface
{
    /**
     * Страница комментариев со статусом $status, новые сверху. Номер страницы приводится в диапазон:
     * за пределами — последняя, 0 и меньше — первая. В пустой выборке одна пустая страница.
     */
    public function page(CommentStatus $status, int $page, int $perPage): CommentPage;
}
