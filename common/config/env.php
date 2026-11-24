<?php

declare(strict_types=1);

/**
 * Конфиг читает только переменные окружения. Нет переменной — приложение не стартует
 * и называет её в ошибке, а не работает на молчаливом умолчании.
 *
 * @return Closure(string): string
 */
return static function (string $name): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException(sprintf('Переменная окружения %s не задана. Список переменных — в .env.example.', $name));
    }

    return $value;
};
