<?php

declare(strict_types=1);

use common\web\PlaceholderController;
use yii\log\FileTarget;
use yii\redis\Session;

/** @var Closure(string): string $env */
$env = require dirname(__DIR__, 2) . '/common/config/env.php';

return [
    'id' => 'app-backend',
    'name' => 'Админка комментариев',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'backend\controllers',
    'defaultRoute' => 'placeholder/index',
    'controllerMap' => [
        'placeholder' => PlaceholderController::class,
    ],
    'components' => [
        'request' => [
            'cookieValidationKey' => $env('BACKEND_COOKIE_KEY'),
            'csrfParam' => '_csrf-backend',
        ],
        // Сессии админки в общем Redis под префиксом session:.
        'session' => [
            'class' => Session::class,
            'name' => 'comments-admin',
            'keyPrefix' => 'session:',
            'cookieParams' => ['httponly' => true, 'samesite' => 'Lax'],
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
