<?php

declare(strict_types=1);

namespace common\redis;

/**
 * Команды Redis, которые нужны приложению. Unit-тесты мокают этот интерфейс, а не компонент yii2-redis.
 * Любой сбой соединения — RedisUnavailableException.
 */
interface RedisClientInterface
{
    public function incr(string $key): int;

    public function expire(string $key, int $seconds): void;

    public function get(string $key): ?string;

    public function del(string $key): void;

    /** SET NX EX: записывает значение с TTL, только если ключа ещё нет. */
    public function setNxEx(string $key, string $value, int $ttlSeconds): void;

    /** GETDEL: читает значение и удаляет ключ одной командой. Ключа нет — null. */
    public function getDel(string $key): ?string;
}
