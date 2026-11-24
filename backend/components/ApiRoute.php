<?php

declare(strict_types=1);

namespace backend\components;

use Yii;

/**
 * Адреса JSON-API админки: всё под `/api/`. Их ответы, в том числе ошибки, — JSON (REG-A-21, REG-A-61b).
 */
final class ApiRoute
{
    private const string PREFIX = 'api/';

    /** Текущий запрос идёт на адрес под `/api/`. */
    public static function isCurrent(): bool
    {
        return str_starts_with(Yii::$app->request->getPathInfo(), self::PREFIX);
    }
}
