<?php

declare(strict_types=1);

namespace common\redis;

use Override;
use yii\db\Exception;
use yii\redis\Connection;

/** Адаптер над компонентом yiisoft/yii2-redis. */
final readonly class YiiRedisClient implements RedisClientInterface
{
    public function __construct(private Connection $connection) {}

    #[Override]
    public function incr(string $key): int
    {
        $counter = $this->execute('INCR', [$key]);

        return is_numeric($counter) ? (int) $counter : throw new RedisUnavailableException('Redis: INCR вернул не число');
    }

    #[Override]
    public function expire(string $key, int $seconds): void
    {
        $this->execute('EXPIRE', [$key, $seconds]);
    }

    #[Override]
    public function get(string $key): ?string
    {
        $value = $this->execute('GET', [$key]);

        return is_string($value) ? $value : null;
    }

    #[Override]
    public function del(string $key): void
    {
        $this->execute('DEL', [$key]);
    }

    #[Override]
    public function setNxEx(string $key, string $value, int $ttlSeconds): void
    {
        $this->execute('SET', [$key, $value, 'NX', 'EX', $ttlSeconds]);
    }

    #[Override]
    public function getDel(string $key): ?string
    {
        $value = $this->execute('GETDEL', [$key]);

        return is_string($value) ? $value : null;
    }

    #[Override]
    public function publish(string $channel, string $message): void
    {
        $this->execute('PUBLISH', [$channel, $message]);
    }

    /**
     * @param list<int|string> $params
     */
    private function execute(string $command, array $params): mixed
    {
        try {
            return $this->connection->executeCommand($command, $params);
        } catch (Exception $exception) {
            throw new RedisUnavailableException(sprintf('Redis: команда %s не выполнена', $command), 0, $exception);
        }
    }
}
