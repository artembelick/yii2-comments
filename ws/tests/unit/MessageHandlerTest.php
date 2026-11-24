<?php

declare(strict_types=1);

namespace ws\tests\unit;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\models\User;
use common\redis\RedisClientInterface;
use common\redis\RedisUnavailableException;
use common\repositories\UserRepositoryInterface;
use common\security\WsTokenService;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\Stub;
use ws\ActiveAdminChecker;
use ws\ConnectionRegistry;
use ws\MessageHandler;
use ws\OriginPolicy;
use ws\tests\_support\AnsweringUsers;
use ws\tests\_support\FakeConnection;
use ws\tests\_support\FakeTimer;
use yii\db\Connection;
use yii\db\Exception;

final class MessageHandlerTest extends Unit
{
    private const string ADMIN_ORIGIN = 'http://admin.comments.localhost';

    private const int ADMIN_ID = 7;

    private const string SESSION_ID = 'session-of-anna';

    private const string AUTH_KEY = 'auth-key-of-anna';

    /** Токен верного формата: 43 символа base64url. */
    private const string TOKEN = 'Abcdefghijklmnopqrstuvwxyz0123456789-_ABCDE';

    #[TestDox('UNIT-MH-01: onConnect ставит таймер авторизации на 5 секунд и таймер тишины на 60')]
    public function testConnectStartsAuthTimer(): void
    {
        $timer = new FakeTimer();
        $handler = $this->handler(new ConnectionRegistry(), $timer);

        $handler->onConnect(new FakeConnection('1'), self::ADMIN_ORIGIN);

        self::assertSame([5.0, 60.0], $timer->pendingDelays());
    }

    #[TestDox('UNIT-MH-10: handshake с чужим Origin отклоняется 403, соединение не регистрируется и таймеров нет')]
    public function testForeignOriginIsRejected(): void
    {
        $timer = new FakeTimer();
        $connection = new FakeConnection('1');
        $handler = $this->handler(new ConnectionRegistry(), $timer);

        $handler->onConnect($connection, 'http://evil.example');

        self::assertSame([true, []], [$connection->rejected, $timer->pendingDelays()]);
    }

    #[TestDox('UNIT-MH-02: за 5 секунд не авторизовался → close(4401)')]
    public function testSilentUnauthenticatedConnectionIsClosed(): void
    {
        $timer = new FakeTimer();
        $connection = new FakeConnection('1');
        $handler = $this->handler(new ConnectionRegistry(), $timer);
        $handler->onConnect($connection, self::ADMIN_ORIGIN);

        $timer->elapse(5.0);

        self::assertSame(4401, $connection->closedWith);
    }

    #[TestDox('UNIT-MH-03: верный auth → токен погашен, админ активен, соединение авторизовано, таймер авторизации снят, auth.ok')]
    public function testValidAuthAuthenticates(): void
    {
        $registry = new ConnectionRegistry();
        $timer = new FakeTimer();
        $connection = new FakeConnection('1');
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->expects(self::once())->method('getDel')->with('ws-token:' . self::TOKEN)->willReturn($this->claims());
        $handler = $this->handler($registry, $timer, $redis);
        $handler->onConnect($connection, self::ADMIN_ORIGIN);

        $handler->onMessage($connection, $this->auth(self::TOKEN));

        self::assertSame([['type' => 'auth.ok']], $connection->sent);
        self::assertSame([$connection], $registry->bySession(self::SESSION_ID));
        self::assertSame([60.0], $timer->pendingDelays());
        self::assertNull($connection->closedWith);
    }

    #[TestDox('UNIT-MH-04: токен не погашен (неверный, истёк, уже использован) → close(4401)')]
    public function testUnknownTokenIsUnauthorized(): void
    {
        $registry = new ConnectionRegistry();
        $connection = new FakeConnection('1');
        $handler = $this->handler($registry, new FakeTimer(), $this->redisWith(null));
        $handler->onConnect($connection, self::ADMIN_ORIGIN);

        $handler->onMessage($connection, $this->auth(self::TOKEN));

        self::assertSame([4401, [], []], [$connection->closedWith, $connection->sent, $registry->authenticated()]);
    }

    #[TestDox('UNIT-MH-04: админ по токену не активен (заблокирован) → close(4401)')]
    public function testInactiveAdminIsUnauthorized(): void
    {
        $registry = new ConnectionRegistry();
        $connection = new FakeConnection('1');
        $handler = $this->handler($registry, new FakeTimer(), users: $this->usersWith(null));
        $handler->onConnect($connection, self::ADMIN_ORIGIN);

        $handler->onMessage($connection, $this->auth(self::TOKEN));

        self::assertSame([4401, [], []], [$connection->closedWith, $connection->sent, $registry->authenticated()]);
    }

    #[TestDox('UNIT-MH-04: после выдачи токена админу сменили пароль (auth_key другой) → close(4401)')]
    public function testTokenIssuedBeforePasswordChangeIsUnauthorized(): void
    {
        $registry = new ConnectionRegistry();
        $connection = new FakeConnection('1');
        $passwordChanged = new User(['id' => self::ADMIN_ID, 'status' => User::STATUS_ACTIVE, 'auth_key' => 'auth-key-after-password-change']);
        $handler = $this->handler($registry, new FakeTimer(), users: $this->usersWith($passwordChanged));
        $handler->onConnect($connection, self::ADMIN_ORIGIN);

        $handler->onMessage($connection, $this->auth(self::TOKEN));

        self::assertSame([4401, [], []], [$connection->closedWith, $connection->sent, $registry->authenticated()]);
    }

    #[TestDox('UNIT-MH-04: сессия токена завершена (logout после выдачи) → close(4401)')]
    public function testTokenOfEndedSessionIsUnauthorized(): void
    {
        $registry = new ConnectionRegistry();
        $connection = new FakeConnection('1');
        $redis = $this->redisWith($this->claims());
        $redis->method('get')->willReturnMap([['ws-session-ended:' . self::SESSION_ID, '1']]);
        $handler = $this->handler($registry, new FakeTimer(), $redis);
        $handler->onConnect($connection, self::ADMIN_ORIGIN);

        $handler->onMessage($connection, $this->auth(self::TOKEN));

        self::assertSame([4401, [], []], [$connection->closedWith, $connection->sent, $registry->authenticated()]);
    }

    #[TestDox('UNIT-MH-05: проверить токен или админа не удалось (БД или Redis недоступны) → close(1011)')]
    #[DataProvider('unavailableBoundaries')]
    public function testUnavailableCheckIsInternalError(string $boundary): void
    {
        $registry = new ConnectionRegistry();
        $connection = new FakeConnection('1');
        $handler = $this->handler($registry, new FakeTimer(), ...$this->unavailable($boundary));
        $handler->onConnect($connection, self::ADMIN_ORIGIN);

        $handler->onMessage($connection, $this->auth(self::TOKEN));

        self::assertSame([1011, [], []], [$connection->closedWith, $connection->sent, $registry->authenticated()]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unavailableBoundaries(): array
    {
        return [
            'БД не ответила и после переподключения (AdminCheckUnavailableException)' => ['db'],
            'Redis не ответил на GETDEL' => ['redis'],
        ];
    }

    #[TestDox('UNIT-MH-06: нарушение протокола → close(4400)')]
    #[DataProvider('protocolViolations')]
    public function testProtocolViolationIsClosed(string $raw): void
    {
        $connection = new FakeConnection('1');
        $handler = $this->handler(new ConnectionRegistry(), new FakeTimer());
        $handler->onConnect($connection, self::ADMIN_ORIGIN);

        $handler->onMessage($connection, $raw);

        self::assertSame([4400, []], [$connection->closedWith, $connection->sent]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function protocolViolations(): array
    {
        return [
            'больше 4096 байт' => [json_encode(['type' => 'auth', 'token' => str_repeat('a', 4096)], JSON_THROW_ON_ERROR)],
            'не JSON' => ['{"type":'],
            'JSON не объект' => ['"auth"'],
            'JSON без type' => ['{"token":"' . self::TOKEN . '"}'],
            'type не строка' => ['{"type":["auth"]}'],
            'неизвестный type' => ['{"type":"subscribe"}'],
            'ping до авторизации' => ['{"type":"ping"}'],
        ];
    }

    #[TestDox('UNIT-MH-06: повторный auth после auth.ok → close(4400)')]
    public function testSecondAuthIsViolation(): void
    {
        $connection = new FakeConnection('1');
        $handler = $this->handler(new ConnectionRegistry(), new FakeTimer());
        $handler->onConnect($connection, self::ADMIN_ORIGIN);
        $handler->onMessage($connection, $this->auth(self::TOKEN));

        $handler->onMessage($connection, $this->auth(self::TOKEN));

        self::assertSame([4400, [['type' => 'auth.ok']]], [$connection->closedWith, $connection->sent]);
    }

    #[TestDox('UNIT-MH-06: сообщение ровно 4096 байт — не нарушение размера')]
    public function testMessageOfLimitSizeIsAccepted(): void
    {
        $padded = str_pad($this->auth(self::TOKEN), 4096, ' ');
        $connection = new FakeConnection('1');
        $handler = $this->handler(new ConnectionRegistry(), new FakeTimer());
        $handler->onConnect($connection, self::ADMIN_ORIGIN);

        $handler->onMessage($connection, $padded);

        self::assertSame([null, [['type' => 'auth.ok']]], [$connection->closedWith, $connection->sent]);
    }

    #[TestDox('UNIT-MH-07: ping после авторизации → pong')]
    public function testPingIsAnsweredWithPong(): void
    {
        $connection = new FakeConnection('1');
        $handler = $this->handler(new ConnectionRegistry(), new FakeTimer());
        $handler->onConnect($connection, self::ADMIN_ORIGIN);
        $handler->onMessage($connection, $this->auth(self::TOKEN));

        $handler->onMessage($connection, '{"type":"ping"}');

        self::assertSame([null, [['type' => 'auth.ok'], ['type' => 'pong']]], [$connection->closedWith, $connection->sent]);
    }

    #[TestDox('UNIT-MH-11: ping после авторизации перепроверяет доступ: админ заблокирован или сменил пароль → close(4403), pong не уходит')]
    #[DataProvider('endedAccess')]
    public function testPingClosesConnectionWhoseAccessEnded(?User $adminAfterAuth): void
    {
        $connection = new FakeConnection('1');
        $handler = $this->handler(new ConnectionRegistry(), new FakeTimer(), users: new AnsweringUsers($this->activeAdmin(), $adminAfterAuth));
        $handler->onConnect($connection, self::ADMIN_ORIGIN);
        $handler->onMessage($connection, $this->auth(self::TOKEN));

        $handler->onMessage($connection, '{"type":"ping"}');

        self::assertSame([4403, [['type' => 'auth.ok']]], [$connection->closedWith, $connection->sent]);
    }

    /**
     * @return array<string, array{?User}>
     */
    public static function endedAccess(): array
    {
        return [
            'заблокирован' => [null],
            'сменил пароль (auth_key другой)' => [new User(['id' => self::ADMIN_ID, 'status' => User::STATUS_ACTIVE, 'auth_key' => 'auth-key-after-password-change'])],
        ];
    }

    #[TestDox('UNIT-MH-11: ping, а перепроверить доступ не удалось (БД недоступна и после переподключения) → close(1011)')]
    public function testPingWithUnavailableCheckIsInternalError(): void
    {
        $connection = new FakeConnection('1');
        $dbDown = new Exception('server closed the connection unexpectedly');
        $handler = $this->handler(new ConnectionRegistry(), new FakeTimer(), users: new AnsweringUsers($this->activeAdmin(), $dbDown, $dbDown));
        $handler->onConnect($connection, self::ADMIN_ORIGIN);
        $handler->onMessage($connection, $this->auth(self::TOKEN));

        $handler->onMessage($connection, '{"type":"ping"}');

        self::assertSame([1011, [['type' => 'auth.ok']]], [$connection->closedWith, $connection->sent]);
    }

    #[TestDox('UNIT-MH-08: каждое входящее сообщение отменяет таймер тишины и ставит новый на 60 секунд')]
    public function testMessageRestartsSilenceTimer(): void
    {
        $timer = new FakeTimer();
        $connection = new FakeConnection('1');
        $handler = $this->handler(new ConnectionRegistry(), $timer);
        $handler->onConnect($connection, self::ADMIN_ORIGIN);
        $handler->onMessage($connection, $this->auth(self::TOKEN));

        $handler->onMessage($connection, '{"type":"ping"}');

        self::assertSame([[5.0, 60.0, 60.0, 60.0], [60.0]], [$timer->scheduledDelays(), $timer->pendingDelays()]);
    }

    #[TestDox('UNIT-MH-08: таймер тишины сработал → close(1001)')]
    public function testSilenceClosesConnection(): void
    {
        $timer = new FakeTimer();
        $connection = new FakeConnection('1');
        $handler = $this->handler(new ConnectionRegistry(), $timer);
        $handler->onConnect($connection, self::ADMIN_ORIGIN);
        $handler->onMessage($connection, $this->auth(self::TOKEN));

        $timer->elapse(60.0);

        self::assertSame(1001, $connection->closedWith);
    }

    #[TestDox('UNIT-MH-09: onClose убирает соединение из реестра и отменяет его таймеры')]
    public function testCloseForgetsConnection(): void
    {
        $registry = new ConnectionRegistry();
        $timer = new FakeTimer();
        $connection = new FakeConnection('1');
        $handler = $this->handler($registry, $timer);
        $handler->onConnect($connection, self::ADMIN_ORIGIN);
        $handler->onMessage($connection, $this->auth(self::TOKEN));

        $handler->onClose($connection);

        self::assertSame([[], []], [$timer->pendingDelays(), $registry->authenticated()]);
    }

    #[TestDox('UNIT-MH-09: onClose отклонённого handshake ничего не ломает')]
    public function testCloseOfRejectedConnectionIsHarmless(): void
    {
        $timer = new FakeTimer();
        $connection = new FakeConnection('1');
        $handler = $this->handler(new ConnectionRegistry(), $timer);
        $handler->onConnect($connection, 'http://evil.example');

        $handler->onClose($connection);

        self::assertSame([], $timer->pendingDelays());
    }

    private function handler(
        ConnectionRegistry $registry,
        FakeTimer $timer,
        ?RedisClientInterface $redis = null,
        ?UserRepositoryInterface $users = null,
    ): MessageHandler {
        return new MessageHandler(
            $registry,
            new WsTokenService($redis ?? $this->redisWith($this->claims())),
            new ActiveAdminChecker($users ?? $this->usersWith($this->activeAdmin()), self::createStub(Connection::class)),
            $timer,
            new OriginPolicy([self::ADMIN_ORIGIN]),
        );
    }

    /**
     * Redis отдаёт на GETDEL это значение.
     *
     * @return RedisClientInterface&Stub
     */
    private function redisWith(?string $stored): RedisClientInterface
    {
        $redis = self::createStub(RedisClientInterface::class);
        $redis->method('getDel')->willReturn($stored);

        return $redis;
    }

    private function usersWith(?User $active): UserRepositoryInterface
    {
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findActiveById')->willReturn($active);

        return $users;
    }

    private function claims(): string
    {
        return json_encode(['userId' => self::ADMIN_ID, 'sessionId' => self::SESSION_ID, 'authKey' => self::AUTH_KEY], JSON_THROW_ON_ERROR);
    }

    private function activeAdmin(): User
    {
        return new User(['id' => self::ADMIN_ID, 'status' => User::STATUS_ACTIVE, 'auth_key' => self::AUTH_KEY]);
    }

    private function auth(string $token): string
    {
        return json_encode(['type' => 'auth', 'token' => $token], JSON_THROW_ON_ERROR);
    }

    /**
     * Границы, одна из которых не отвечает: Redis при погашении токена или БД при проверке админа (и при повторе).
     *
     * @return array{redis?: RedisClientInterface, users?: UserRepositoryInterface}
     */
    private function unavailable(string $boundary): array
    {
        $redis = self::createStub(RedisClientInterface::class);
        $redis->method('getDel')->willThrowException(new RedisUnavailableException('connection refused'));
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findActiveById')->willThrowException(new Exception('server closed the connection unexpectedly'));

        return ['redis' => ['redis' => $redis], 'db' => ['users' => $users]][$boundary];
    }
}
