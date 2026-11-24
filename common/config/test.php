<?php

declare(strict_types=1);

use yii\helpers\ArrayHelper;

/**
 * Приложение для unit-тестов common и для фикстур REST-regression (модуль Yii2 Codeception):
 * консольное, с общими компонентами. Транзакцию с откатом вокруг теста открывает модуль Yii2.
 *
 * @var array<string, mixed> $common
 */
$common = require __DIR__ . '/main.php';

return ArrayHelper::merge($common, [
    'id' => 'app-common-tests',
    'basePath' => dirname(__DIR__),
    'components' => [
        // bcrypt с рабочим cost 13 — сотни миллисекунд на хэш. В тестах важна проверка, а не стойкость.
        'security' => ['passwordHashCost' => 4],
    ],
]);
