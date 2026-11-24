<?php

declare(strict_types=1);

namespace common\events;

use common\redis\RedisClientInterface;
use common\redis\RedisUnavailableException;
use Override;

/** События в Redis pub/sub: JSON без экранирования Unicode, как его шлёт клиентам WS-сервер. */
final readonly class RedisEventPublisher implements EventPublisherInterface
{
    public function __construct(private RedisClientInterface $redis) {}

    #[Override]
    public function publish(string $channel, array $message): void
    {
        try {
            $this->redis->publish($channel, json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (RedisUnavailableException $redisUnavailableException) {
            throw new EventPublishException(sprintf('Событие в %s не опубликовано', $channel), 0, $redisUnavailableException);
        }
    }
}
