<?php

declare(strict_types=1);

namespace ws;

use yii\redis\Connection;

/**
 * Настройки подписчика workerman/redis из компонента redis приложения: подписка и публикация ходят в один Redis
 * с одними учётными данными. Номер базы не нужен: каналы pub/sub общие для всех баз.
 */
final readonly class RedisSubscriberSettings
{
    /** Таймаут соединения workerman/redis по умолчанию, когда компонент его не задаёт (по умолчанию там null). */
    private const float DEFAULT_CONNECT_TIMEOUT = 5.0;

    /**
     * @param array{connect_timeout: float|int, ssl: bool, context: array<mixed>} $options опции Workerman\Redis\Client
     * @param string|list<string>|null $auth аргумент AUTH; null — Redis без пароля
     */
    private function __construct(public string $address, public array $options, public string|array|null $auth) {}

    public static function of(Connection $redis): self
    {
        // В PHPDoc yii2-redis connectionTimeout — float, но по умолчанию он null: тогда таймаут workerman/redis.
        $connectionTimeout = $redis->connectionTimeout > 0 ? $redis->connectionTimeout : self::DEFAULT_CONNECT_TIMEOUT;

        return new self(
            $redis->unixSocket === null ? sprintf('redis://%s:%d', $redis->hostname, $redis->port) : 'unix://' . $redis->unixSocket,
            [
                'connect_timeout' => $connectionTimeout,
                'ssl' => $redis->useSSL,
                'context' => $redis->contextOptions,
            ],
            self::auth($redis),
        );
    }

    /**
     * @return string|list<string>|null
     */
    private static function auth(Connection $redis): string|array|null
    {
        if ($redis->password === null) {
            return null;
        }

        return $redis->username === null ? $redis->password : [$redis->username, $redis->password];
    }
}
