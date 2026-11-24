<?php

declare(strict_types=1);

use common\models\User;
use yii\log\FileTarget;
use yii\redis\Session;

/** @var Closure(string): string $env */
$env = require dirname(__DIR__, 2) . '/common/config/env.php';

return [
    'id' => 'app-backend',
    'name' => 'Админка комментариев',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'backend\controllers',
    'defaultRoute' => 'comment/index',
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
        'user' => [
            'identityClass' => User::class,
            'enableAutoLogin' => false,
            'loginUrl' => ['auth/login'],
        ],
        // Маршруты админки (docs/test-plans/unit.md, «Веб-слой»). Всё, что не описано, — 404.
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => true,
            'rules' => [
                '' => 'comment/index',
                'login' => 'auth/login',
                'logout' => 'auth/logout',
            ],
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
