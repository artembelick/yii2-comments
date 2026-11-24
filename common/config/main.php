<?php

declare(strict_types=1);

use common\redis\RedisClientInterface;
use common\redis\YiiRedisClient;
use common\repositories\ActiveRecordCommentRepository;
use common\repositories\ActiveRecordUserRepository;
use common\repositories\CommentRepositoryInterface;
use common\repositories\UserRepositoryInterface;
use common\security\RateLimiter;
use common\security\RateLimiterInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
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
    // Реализации интерфейсов для DI. Unit-тесты подменяют их моками (docs/test-plans/unit.md, «Изоляция»).
    'container' => [
        'definitions' => [
            UserRepositoryInterface::class => ActiveRecordUserRepository::class,
            CommentRepositoryInterface::class => ActiveRecordCommentRepository::class,
            ClockInterface::class => NativeClock::class,
            RedisClientInterface::class => static fn(): YiiRedisClient => new YiiRedisClient(Yii::$app->get('redis')),
            RateLimiterInterface::class => RateLimiter::class,
        ],
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
        // В БД и JSON время в UTC, на экране — в DISPLAY_TIMEZONE (docs/test-plans/unit.md, «Схема»).
        'formatter' => [
            'defaultTimeZone' => 'UTC',
            'timeZone' => $env('DISPLAY_TIMEZONE'),
            'datetimeFormat' => 'php:d.m.Y H:i',
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
