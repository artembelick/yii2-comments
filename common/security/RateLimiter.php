<?php

declare(strict_types=1);

namespace common\security;

use Closure;
use common\redis\RedisClientInterface;
use common\redis\RedisUnavailableException;
use Override;

/**
 * Фиксированное окно в Redis: INCR счётчика, EXPIRE на окно при первой попытке.
 * Все ключи лежат под префиксом rate-limit: — тесты стенда сбрасывают только его.
 */
final readonly class RateLimiter implements RateLimiterInterface
{
    private const string PREFIX = 'rate-limit:';

    public function __construct(private RedisClientInterface $redis) {}

    #[Override]
    public function hit(string $key, int $limit, int $windowSeconds): bool
    {
        return $this->guarded(function () use ($key, $limit, $windowSeconds): bool {
            $counter = $this->redis->incr(self::PREFIX . $key);
            if ($counter === 1) {
                $this->redis->expire(self::PREFIX . $key, $windowSeconds);
            }

            return $counter <= $limit;
        });
    }

    #[Override]
    public function isExceeded(string $key, int $limit): bool
    {
        return $this->guarded(fn(): bool => (int) $this->redis->get(self::PREFIX . $key) >= $limit);
    }

    #[Override]
    public function reset(string $key): void
    {
        $this->guarded(function () use ($key): bool {
            $this->redis->del(self::PREFIX . $key);

            return true;
        });
    }

    /**
     * Сбой Redis превращается в RateLimiterUnavailableException: вызывающий код решает, что делать без лимита.
     *
     * @param Closure(): bool $operation
     */
    private function guarded(Closure $operation): bool
    {
        try {
            return $operation();
        } catch (RedisUnavailableException $redisUnavailableException) {
            throw new RateLimiterUnavailableException('Счётчики лимита недоступны', 0, $redisUnavailableException);
        }
    }
}
