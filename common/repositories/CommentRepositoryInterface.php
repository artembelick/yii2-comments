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

    /** Новый текст активного комментария с отметкой, кто и когда правил. false — комментарий удалён, ничего не изменилось. */
    public function updateText(int $id, string $text, int $adminId, DateTimeImmutable $now): bool;

    /** Удаляет активный комментарий от имени админа. false — уже удалён: прежние deleted_at и deleted_by остаются. */
    public function markDeleted(int $id, int $adminId, DateTimeImmutable $now): bool;

    /** Возвращает удалённый комментарий в ленту. false — уже активен. */
    public function markRestored(int $id): bool;

    /**
     * Страница комментариев со статусом $status, новые сверху. Номер страницы приводится в диапазон:
     * за пределами — последняя, 0 и меньше — первая. В пустой выборке одна пустая страница.
     */
    public function page(CommentStatus $status, int $page, int $perPage): CommentPage;
}
