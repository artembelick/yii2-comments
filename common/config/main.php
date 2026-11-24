<?php

declare(strict_types=1);

use yii\base\Event;
use yii\db\Connection;
use yii\i18n\PhpMessageSource;
use yii\redis\Connection as RedisConnection;

/** @var Closure(string): string $env */
$env = require __DIR__ . '/env.php';

return [
    'language' => 'ru-RU',
    'sourceLanguage' => 'en-US',
    'timeZone' => 'UTC',
    'vendorPath' => dirname(__DIR__, 2) . '/vendor',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'components' => [
        'db' => [
            'class' => Connection::class,
            'dsn' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $env('POSTGRES_HOST'), $env('POSTGRES_PORT'), $env('POSTGRES_DB')),
            'username' => $env('POSTGRES_USER'),
            'password' => $env('POSTGRES_PASSWORD'),
            'charset' => 'utf8',
            'on afterOpen' => static function (Event $event): void {
                /** @var Connection $db */
                $db = $event->sender;
                $db->createCommand("SET TIME ZONE 'UTC'")->execute();
            },
        ],
        'redis' => [
            'class' => RedisConnection::class,
            'hostname' => $env('REDIS_HOST'),
            'port' => (int) $env('REDIS_PORT'),
            'database' => 0,
        ],
        'i18n' => [
            'translations' => [
                'app' => [
                    'class' => PhpMessageSource::class,
                    'basePath' => '@common/messages',
                ],
            ],
        ],
    ],
];
