<?php

declare(strict_types=1);

namespace common\security;

/**
 * Лимит запросов с фиксированным окном. Ключ без префикса: `login:{lower(login)}:{ip}`, `comment:{ip}`.
 * Если хранилище недоступно, методы выбрасывают RateLimiterUnavailableException, а что делать дальше, решает вызывающий код.
 */
interface RateLimiterInterface
{
    /** Засчитывает попытку. true — попытка в пределах лимита. */
    public function hit(string $key, int $limit, int $windowSeconds): bool;

    public function isExceeded(string $key, int $limit): bool;

    public function reset(string $key): void;
}
