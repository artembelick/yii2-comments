<?php

declare(strict_types=1);

namespace ws\tests\unit;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\security\WsTokenClaims;
use common\tests\_support\RecordingLogger;
use Override;
use PHPUnit\Framework\Attributes\TestDox;
use ws\CloseCode;
use ws\ConnectionRegistry;
use ws\RedisDispatcher;
use ws\tests\_support\FailingConnection;
use ws\tests\_support\FakeConnection;
use Yii;
use yii\log\Logger;

final class RedisDispatcherTest extends Unit
{
    private const array CREATED = [
        'type' => 'comment.created',
        'comment' => ['id' => 42, 'author' => 'Ян', 'text' => 'Комментарий', 'status' => 'active'],
    ];

    #[Override]
    protected function _after(): void
    {
        Yii::setLogger(null);
    }

    #[TestDox('UNIT-DSP-01: comments.events уходит как есть всем авторизованным соединениям и не уходит неавторизованным')]
    public function testCommentEventReachesAuthenticatedOnly(): void
    {
        $registry = new ConnectionRegistry();
        $anna = $this->authenticated($registry, '1', 7);
        $boris = $this->authenticated($registry, '2', 8);
        $stranger = new FakeConnection('3');
        $registry->add($stranger);

        (new RedisDispatcher($registry))->dispatch('comments.events', json_encode(self::CREATED, JSON_THROW_ON_ERROR));

        self::assertSame([[self::CREATED], [self::CREATED], []], [$anna->sent, $boris->sent, $stranger->sent]);
    }

    #[TestDox('UNIT-DSP-02: ws.control session.ended → close(4403) всем соединениям этой сессии, остальные не тронуты')]
    public function testSessionEndedClosesOnlyThatSession(): void
    {
        $registry = new ConnectionRegistry();
        $firstTab = $this->authenticatedIn($registry, '1', 7, 'session-a');
        $secondTab = $this->authenticatedIn($registry, '2', 7, 'session-a');
        $otherSession = $this->authenticatedIn($registry, '3', 7, 'session-b');
        $boris = $this->authenticatedIn($registry, '4', 8, 'session-c');

        (new RedisDispatcher($registry))->dispatch('ws.control', '{"type":"session.ended","sessionId":"session-a"}');

        self::assertSame(
            [CloseCode::SESSION_ENDED, CloseCode::SESSION_ENDED, null, null],
            [$firstTab->closedWith, $secondTab->closedWith, $otherSession->closedWith, $boris->closedWith],
        );
    }

    #[TestDox('UNIT-DSP-03: ws.control access.revoked → close(4403) всем соединениям админа, чужие не тронуты')]
    public function testAccessRevokedClosesAllConnectionsOfAdmin(): void
    {
        $registry = new ConnectionRegistry();
        $annaFirst = $this->authenticatedIn($registry, '1', 7, 'session-a');
        $annaSecond = $this->authenticatedIn($registry, '2', 7, 'session-b');
        $boris = $this->authenticatedIn($registry, '3', 8, 'session-c');

        (new RedisDispatcher($registry))->dispatch('ws.control', '{"type":"access.revoked","userId":7}');

        self::assertSame(
            [CloseCode::SESSION_ENDED, CloseCode::SESSION_ENDED, null],
            [$annaFirst->closedWith, $annaSecond->closedWith, $boris->closedWith],
        );
    }

    #[TestDox('UNIT-DSP-04: непонятное сообщение → ничего не отправлено, предупреждение в логе')]
    #[DataProvider('unknownMessages')]
    public function testUnknownMessageIsLoggedAndDropped(string $channel, string $payload): void
    {
        $registry = new ConnectionRegistry();
        $anna = $this->authenticated($registry, '1', 7);
        $log = new RecordingLogger();
        Yii::setLogger($log);

        (new RedisDispatcher($registry))->dispatch($channel, $payload);

        self::assertSame([[], null], [$anna->sent, $anna->closedWith]);
        self::assertSame([Logger::LEVEL_WARNING], $log->levelsOf(RedisDispatcher::class));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unknownMessages(): array
    {
        $created = json_encode(self::CREATED, JSON_THROW_ON_ERROR);

        return [
            'битый JSON' => ['comments.events', '{"type":"comment.created",'],
            'JSON не объект' => ['comments.events', '"comment.created"'],
            'неизвестный канал' => ['comments.other', $created],
            'неизвестный type' => ['comments.events', '{"type":"comment.liked","comment":{"id":42}}'],
            'нет type' => ['comments.events', '{"comment":{"id":42}}'],
            'событие комментария в ws.control' => ['ws.control', $created],
            'неизвестный type в ws.control' => ['ws.control', '{"type":"session.paused","sessionId":"session-1"}'],
            'session.ended без sessionId' => ['ws.control', '{"type":"session.ended"}'],
            'access.revoked с userId не числом' => ['ws.control', '{"type":"access.revoked","userId":"7"}'],
            'управляющее сообщение в comments.events' => ['comments.events', '{"type":"session.ended","sessionId":"session-1"}'],
        ];
    }

    #[TestDox('UNIT-DSP-05: соединение упало на send → рассылка остальным продолжается, упавшее закрыто 1011')]
    public function testFailedSendClosesOnlyThatConnection(): void
    {
        $registry = new ConnectionRegistry();
        $anna = $this->authenticated($registry, '1', 7);
        $broken = new FailingConnection('2');
        $registry->add($broken);
        $registry->authenticate($broken, new WsTokenClaims(8, 'session-2', 'auth-key'));

        $boris = $this->authenticated($registry, '3', 9);

        (new RedisDispatcher($registry))->dispatch('comments.events', json_encode(self::CREATED, JSON_THROW_ON_ERROR));

        self::assertSame(
            [[self::CREATED], CloseCode::INTERNAL_ERROR, [self::CREATED]],
            [$anna->sent, $broken->closedWith, $boris->sent],
        );
    }

    private function authenticated(ConnectionRegistry $registry, string $id, int $userId): FakeConnection
    {
        return $this->authenticatedIn($registry, $id, $userId, 'session-' . $id);
    }

    private function authenticatedIn(ConnectionRegistry $registry, string $id, int $userId, string $sessionId): FakeConnection
    {
        $connection = new FakeConnection($id);
        $registry->add($connection);
        $registry->authenticate($connection, new WsTokenClaims($userId, $sessionId, 'auth-key'));

        return $connection;
    }
}
