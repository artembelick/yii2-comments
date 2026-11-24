<?php

declare(strict_types=1);

namespace common\services;

/** Итог правки текста: сохранён новый текст или ввод совпал с текущим. */
enum UpdateResult
{
    case Updated;
    case Unchanged;
}
