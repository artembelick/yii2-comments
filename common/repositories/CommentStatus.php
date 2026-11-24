<?php

declare(strict_types=1);

namespace common\repositories;

/** Вкладка списка: активные или удалённые комментарии. */
enum CommentStatus: string
{
    case Active = 'active';
    case Deleted = 'deleted';
}
