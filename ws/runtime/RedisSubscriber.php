<?php

declare(strict_types=1);

namespace ws\runtime;

use common\events\EventPublisherInterface;
use Workerman\Redis\Client;
use ws\RedisDispatcher;
use ws\RedisSubscriberSettings;
use yii\redis\Connection;

/**
 * Асинхронная подписка процесса на события в Redis (workerman/redis). Логики нет: entrypoint-limits,
 * покрытие — e2e и REG-WS. После разрыва клиент сам переподключается к Redis и повторяет AUTH.
 */
final class RedisSubscriber
{
    /** Клиент живёт, пока живёт процесс: без ссылки на него подписка закрылась бы вместе с объектом. */
    private static ?Client $client = null;

    /** Адрес, TLS и учётные данные те же, что у компонента redis приложения (UNIT-SUB-01…03). */
    public static function start(Connection $redis, RedisDispatcher $dispatcher): void
    {
        $settings = RedisSubscriberSettings::of($redis);
        $client = new Client($settings->address, $settings->options);
        // AUTH встаёт в очередь раньше SUBSCRIBE. Без пароля список пуст и команды нет.
        array_map(static fn(string|array $auth): mixed => $client->auth($auth), array_filter([$settings->auth]));
        $client->subscribe(
            [EventPublisherInterface::COMMENTS, EventPublisherInterface::CONTROL],
            static fn(string $channel, string $payload) => $dispatcher->dispatch($channel, $payload),
        );
        self::$client = $client;
    }
}
