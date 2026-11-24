<?php

declare(strict_types=1);

namespace common\services;

use RuntimeException;

/** Логин или пароль админа не прошли проверку. Ошибки — для вывода человеку, по одной на строку. */
final class InvalidAdminDataException extends RuntimeException
{
    /**
     * @param non-empty-list<string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode("\n", $errors));
    }
}
