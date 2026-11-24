<?php

declare(strict_types=1);

use yii\console\controllers\MigrateController;

return [
    'id' => 'app-console',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'console\controllers',
    'controllerMap' => [
        'migrate' => [
            'class' => MigrateController::class,
            'migrationPath' => '@console/migrations',
            'templateFile' => '@yii/views/migration.php',
        ],
    ],
];
