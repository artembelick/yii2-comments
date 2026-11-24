<?php

declare(strict_types=1);

use common\web\SessionCookie;
use yii\log\FileTarget;

/** @var Closure(string): string $env */
$env = require dirname(__DIR__, 2) . '/common/config/env.php';

return [
    'id' => 'app-frontend',
    'name' => 'Комментарии',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'frontend\controllers',
    'defaultRoute' => 'comment/index',
    'components' => [
        // Ошибки — своим видом в layout публичной части, а не стандартным шаблоном Yii (REG-G-24).
        'errorHandler' => ['errorAction' => 'site/error'],
        'request' => [
            'cookieValidationKey' => $env('FRONTEND_COOKIE_KEY'),
            'csrfParam' => '_csrf-frontend',
        ],
        // Своё имя cookie: сессия гостя не пересекается с сессией админки (REG-G-31).
        'session' => [
            'name' => SessionCookie::PUBLIC_SITE,
            'cookieParams' => ['httponly' => true, 'samesite' => 'Lax'],
        ],
        // Маршруты публичной части (docs/test-plans/unit.md, «Веб-слой»). Всё, что не описано, — 404.
        // Адрес отправки принимает любой метод: GET на него отклоняет VerbFilter с 405 (REG-G-23).
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => true,
            'rules' => [
                '' => 'comment/index',
                'comments' => 'comment/create',
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
