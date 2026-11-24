<?php

declare(strict_types=1);

use yii\console\controllers\MigrateController;
use yii\log\FileTarget;

return [
    'id' => 'app-console',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'console\controllers',
    // Компонент log создаётся при старте: иначе Yii::error не доходит до цели.
    'bootstrap' => ['log'],
    'controllerMap' => [
        'migrate' => [
            'class' => MigrateController::class,
            'migrationPath' => '@console/migrations',
            'templateFile' => '@yii/views/migration.php',
        ],
    ],
    'components' => [
        // Ошибки и предупреждения команд — в stderr рядом с их выводом: оператор видит причину, например сбой Redis.
        'log' => [
            'targets' => [
                [
                    'class' => FileTarget::class,
                    'levels' => ['error', 'warning'],
                    'logFile' => 'php://stderr',
                    'logVars' => [],
                ],
            ],
        ],
    ],
];
