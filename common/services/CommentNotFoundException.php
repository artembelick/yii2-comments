<?php

declare(strict_types=1);

namespace common\services;

use RuntimeException;

/** Комментария с таким id нет. Контроллер отвечает 404. */
final class CommentNotFoundException extends RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct(sprintf('Комментарий %d не найден', $id));
    }
}
