<?php

declare(strict_types=1);

use common\web\PlaceholderController;
use yii\log\FileTarget;

/** @var Closure(string): string $env */
$env = require dirname(__DIR__, 2) . '/common/config/env.php';

return [
    'id' => 'app-frontend',
    'name' => 'Комментарии',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'frontend\controllers',
    'defaultRoute' => 'placeholder/index',
    'controllerMap' => [
        'placeholder' => PlaceholderController::class,
    ],
    'components' => [
        'request' => [
            'cookieValidationKey' => $env('FRONTEND_COOKIE_KEY'),
            'csrfParam' => '_csrf-frontend',
        ],

        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => false,
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => FileTarget::class,
                    'levels' => ['error', 'warning'],
                    'logFile' => 'php://stderr',
                ],
            ],
        ],
    ],
];
