<?php

declare(strict_types=1);

namespace common\tests\unit\events;

use Codeception\Test\Unit;
use common\events\EventPublishException;
use common\events\RedisEventPublisher;
use common\redis\RedisClientInterface;
use common\redis\RedisUnavailableException;
use PHPUnit\Framework\Attributes\TestDox;

final class RedisEventPublisherTest extends Unit
{
    #[TestDox('UNIT-PUB-01: сообщение кодируется в JSON без экранирования Unicode и уходит в PUBLISH channel payload')]
    public function testPublishesJsonToChannel(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->expects(self::once())->method('publish')
            ->with('comments.events', '{"type":"comment.created","comment":{"id":42,"author":"Ян","text":"текст"}}');

        (new RedisEventPublisher($redis))->publish('comments.events', [
            'type' => 'comment.created',
            'comment' => ['id' => 42, 'author' => 'Ян', 'text' => 'текст'],
        ]);
    }

    #[TestDox('UNIT-PUB-02: исключение клиента Redis пробрасывается наружу как EventPublishException')]
    public function testRedisFailureBecomesPublishException(): void
    {
        $redis = self::createStub(RedisClientInterface::class);
        $redis->method('publish')->willThrowException(new RedisUnavailableException('Redis: команда PUBLISH не выполнена'));

        $this->expectException(EventPublishException::class);

        (new RedisEventPublisher($redis))->publish('comments.events', ['type' => 'comment.created']);
    }
}
