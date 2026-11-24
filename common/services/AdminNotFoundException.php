<?php

declare(strict_types=1);

namespace common\services;

use RuntimeException;

/** Админа с таким логином нет. Сообщение — для вывода человеку. */
final class AdminNotFoundException extends RuntimeException {}
