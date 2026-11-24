<?php

declare(strict_types=1);

namespace ws;

use RuntimeException;

/** Проверить, активен ли админ, не удалось: БД не ответила и после переподключения. */
final class AdminCheckUnavailableException extends RuntimeException {}
