<?php

declare(strict_types=1);

use yii\helpers\ArrayHelper;
use yii\log\FileTarget;

/**
 * Yii внутри ws-процесса (ADR 0002): токены WS в Redis и проверка «админ активен» в Postgres.
 * Процесс живёт долго, поэтому лог сбрасывается сразу, а не по завершении запроса.
 *
 * @var array<string, mixed> $common
 */
$common = require dirname(__DIR__, 2) . '/common/config/main.php';

/*
 * Процесс один и зовёт Redis и Postgres синхронно: зависшая зависимость не должна держать весь сервер.
 * Redis: 2 секунды на соединение и на ответ, один повтор (после рестарта Redis первая авторизация не получает 1011).
 * Postgres (параметры libpq в DSN): 2 секунды на соединение, на неподтверждённую отправку и на запрос.
 */
$timeoutSeconds = 2;
/** @var string $dsn */
$dsn = ArrayHelper::getValue($common, 'components.db.dsn');

return ArrayHelper::merge($common, [
    'id' => 'app-ws',
    'basePath' => dirname(__DIR__),
    // Не каталог с кодом ws/runtime: файлы кэша или лога туда не попадут (на Linux CI они были бы от root).
    'runtimePath' => sys_get_temp_dir() . '/comments-ws',
    'components' => [
        'db' => [
            'dsn' => sprintf(
                "%s;connect_timeout=%d;tcp_user_timeout=%d;options='-c statement_timeout=%d'",
                $dsn,
                $timeoutSeconds,
                $timeoutSeconds * 1000,
                $timeoutSeconds * 1000,
            ),
        ],
        'redis' => [
            'connectionTimeout' => $timeoutSeconds,
            'dataTimeout' => $timeoutSeconds,
            'retries' => 1,
        ],
        'log' => [
            'flushInterval' => 1,
            'targets' => [
                [
                    'class' => FileTarget::class,
                    'levels' => ['error', 'warning'],
                    'logFile' => 'php://stderr',
                    'exportInterval' => 1,
                    'logVars' => [],
                ],
            ],
        ],
    ],
]);
