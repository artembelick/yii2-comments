<?php

declare(strict_types=1);

use yii\caching\ArrayCache;
use yii\helpers\ArrayHelper;
use yii\helpers\ReplaceArrayValue;
use yii\web\CacheSession;

/**
 * Публичная часть для unit-тестов контроллеров (модуль Yii2 Codeception). Без сети и файловой системы:
 * сессия в памяти, ассеты не публикуются. CSRF — механизм фреймворка, его проверяет regression, а не unit.
 *
 * @var array<string, mixed> $common
 * @var array<string, mixed> $frontend
 */
$common = require dirname(__DIR__, 2) . '/common/config/test.php';
$frontend = require __DIR__ . '/main.php';

return ArrayHelper::merge($common, $frontend, [
    'id' => 'app-frontend-tests',
    'components' => [
        'request' => [
            'enableCsrfValidation' => false,
            'hostInfo' => 'http://comments.localhost',
            'scriptUrl' => '/index.php',
            'url' => '/',
        ],
        'cache' => ['class' => ArrayCache::class],
        'session' => new ReplaceArrayValue(['class' => CacheSession::class, 'cache' => 'cache']),
        'assetManager' => ['bundles' => false],
    ],
]);
