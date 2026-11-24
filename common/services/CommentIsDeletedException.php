<?php

declare(strict_types=1);

namespace common\services;

use RuntimeException;

/** Удалённый комментарий не правят: сначала его восстанавливают. Контроллер отвечает 409. */
final class CommentIsDeletedException extends RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct(sprintf('Комментарий %d удалён', $id));
    }
}
