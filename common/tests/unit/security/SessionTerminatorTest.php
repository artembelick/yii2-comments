<?php

declare(strict_types=1);

namespace common\tests\unit\security;

use Codeception\Test\Unit;
use common\events\EventPublisherInterface;
use common\events\EventPublishException;
use common\redis\RedisClientInterface;
use common\redis\RedisUnavailableException;
use common\security\SessionTerminator;
use common\security\WsTokenService;
use common\tests\_support\RecordingLogger;
use Override;
use PHPUnit\Framework\Attributes\TestDox;
use Yii;
use yii\log\Logger;

final class SessionTerminatorTest extends Unit
{
    #[Override]
    protected function _after(): void
    {
        Yii::setLogger(null);
    }

    #[TestDox('UNIT-TERM-01: sessionEnded публикует в ws.control сообщение session.ended с id сессии')]
    public function testSessionEndedIsPublished(): void
    {
        $events = $this->createMock(EventPublisherInterface::class);
        $events->expects(self::once())->method('publish')->with('ws.control', ['type' => 'session.ended', 'sessionId' => 'session-a']);

        $this->terminator($events)->sessionEnded('session-a');
    }

    #[TestDox('UNIT-TERM-02: accessRevoked публикует в ws.control сообщение access.revoked с id админа')]
    public function testAccessRevokedIsPublished(): void
    {
        $events = $this->createMock(EventPublisherInterface::class);
        $events->expects(self::once())->method('publish')->with('ws.control', ['type' => 'access.revoked', 'userId' => 7]);

        $this->terminator($events)->accessRevoked(7);
    }

    #[TestDox('UNIT-TERM-03: публикация session.ended упала → исключение не выходит наружу, ошибка в логе')]
    public function testSessionEndedPublishFailureIsLogged(): void
    {
        $log = new RecordingLogger();
        Yii::setLogger($log);

        $this->terminator($this->failingEvents())->sessionEnded('session-a');

        self::assertSame(
            [[Logger::LEVEL_ERROR, 'WS не узнал о завершении сессии session-a: Событие в ws.control не опубликовано']],
            $log->messagesOf(SessionTerminator::class),
        );
    }

    #[TestDox('UNIT-TERM-05: публикация access.revoked упала → EventPublishException наружу: команда сообщает оператору')]
    public function testAccessRevokedPublishFailureIsThrown(): void
    {
        $this->expectException(EventPublishException::class);

        $this->terminator($this->failingEvents())->accessRevoked(7);
    }

    #[TestDox('UNIT-TERM-04: sessionEnded помечает сессию завершённой для токенов WS: выданные ей до logout токены не действуют')]
    public function testSessionEndedInvalidatesItsTokens(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->expects(self::once())->method('setNxEx')->with('ws-session-ended:session-a', '1', 120);

        $this->terminator(self::createStub(EventPublisherInterface::class), $redis)->sessionEnded('session-a');
    }

    #[TestDox('UNIT-TERM-04: Redis недоступен для метки сессии → исключение не выходит наружу, ошибка в логе, session.ended всё равно публикуется')]
    public function testSessionMarkFailureIsLogged(): void
    {
        $redis = self::createStub(RedisClientInterface::class);
        $redis->method('setNxEx')->willThrowException(new RedisUnavailableException('Redis: команда SET не выполнена'));
        $events = $this->createMock(EventPublisherInterface::class);
        $events->expects(self::once())->method('publish');
        $log = new RecordingLogger();
        Yii::setLogger($log);

        $this->terminator($events, $redis)->sessionEnded('session-a');

        self::assertSame(
            [[Logger::LEVEL_ERROR, 'Токены WS сессии session-a не погашены: Redis: команда SET не выполнена']],
            $log->messagesOf(SessionTerminator::class),
        );
    }

    private function terminator(EventPublisherInterface $events, ?RedisClientInterface $redis = null): SessionTerminator
    {
        return new SessionTerminator($events, new WsTokenService($redis ?? self::createStub(RedisClientInterface::class)));
    }

    private function failingEvents(): EventPublisherInterface
    {
        $events = self::createStub(EventPublisherInterface::class);
        $events->method('publish')->willThrowException(new EventPublishException('Событие в ws.control не опубликовано'));

        return $events;
    }
}
