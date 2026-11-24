<?php

declare(strict_types=1);

namespace common\tests\unit\security;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\redis\RedisClientInterface;
use common\security\WsTokenService;
use common\tests\_support\RecordingLogger;
use PHPUnit\Framework\Attributes\TestDox;
use Yii;
use yii\log\Logger;

final class WsTokenServiceTest extends Unit
{
    private const int ADMIN_ID = 7;

    private const string SESSION_ID = 'session-of-anna';

    /** Токен верного формата: 43 символа base64url. */
    private const string TOKEN = 'Abcdefghijklmnopqrstuvwxyz0123456789-_ABCDE';

    #[TestDox('UNIT-TOK-01: issue выдаёт 43 символа base64url и пишет под ws-token:<токен> JSON с userId и sessionId через SET NX EX 60')]
    public function testIssueStoresClaimsUnderToken(): void
    {
        $stored = [];
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->expects(self::once())->method('setNxEx')->willReturnCallback(
            static function (string $key, string $value, int $ttl) use (&$stored): void {
                $stored = [$key, $value, $ttl];
            },
        );

        $token = (new WsTokenService($redis))->issue(self::ADMIN_ID, self::SESSION_ID);

        self::assertMatchesRegularExpression('~^[A-Za-z0-9_-]{43}$~D', $token);
        self::assertSame(['ws-token:' . $token, '{"userId":7,"sessionId":"session-of-anna"}', 60], $stored);
    }

    #[TestDox('UNIT-TOK-02: два issue подряд дают разные токены')]
    public function testEachIssueGivesNewToken(): void
    {
        $tokens = new WsTokenService(self::createStub(RedisClientInterface::class));

        $first = $tokens->issue(self::ADMIN_ID, self::SESSION_ID);
        $second = $tokens->issue(self::ADMIN_ID, self::SESSION_ID);

        self::assertNotSame($first, $second);
    }

    #[TestDox('UNIT-TOK-03: consume гасит токен через GETDEL и возвращает claims')]
    public function testConsumeReturnsClaims(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->expects(self::once())->method('getDel')->with('ws-token:' . self::TOKEN)
            ->willReturn('{"userId":7,"sessionId":"session-of-anna"}');

        $claims = (new WsTokenService($redis))->consume(self::TOKEN);

        self::assertSame([self::ADMIN_ID, self::SESSION_ID], [$claims?->userId, $claims?->sessionId]);
    }

    #[TestDox('UNIT-TOK-03: GETDEL ничего не вернул (токен уже погашен) → null')]
    public function testConsumedTokenGivesNull(): void
    {
        $redis = self::createStub(RedisClientInterface::class);
        $redis->method('getDel')->willReturn(null);

        $claims = (new WsTokenService($redis))->consume(self::TOKEN);

        self::assertNull($claims);
    }

    #[TestDox('UNIT-TOK-04: токен неверного формата → null без обращения к Redis')]
    #[DataProvider('malformedTokens')]
    public function testMalformedTokenIsRejectedWithoutRedis(string $token): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->expects(self::never())->method('getDel');

        $claims = (new WsTokenService($redis))->consume($token);

        self::assertNull($claims);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedTokens(): array
    {
        return [
            'пустой' => [''],
            '42 символа' => [substr(self::TOKEN, 0, 42)],
            '44 символа' => [self::TOKEN . 'A'],
            'символы вне base64url' => [substr(self::TOKEN, 0, 41) . '+/'],
            'перевод строки в конце' => [self::TOKEN . "\n"],
            'шаблон ключа Redis' => [substr(self::TOKEN, 0, 42) . '*'],
        ];
    }

    #[TestDox('UNIT-TOK-05: в Redis под токеном битые данные → null и предупреждение в логе')]
    #[DataProvider('brokenClaims')]
    public function testBrokenClaimsAreLogged(string $stored): void
    {
        $redis = self::createStub(RedisClientInterface::class);
        $redis->method('getDel')->willReturn($stored);
        $log = new RecordingLogger();
        Yii::setLogger($log);

        $claims = (new WsTokenService($redis))->consume(self::TOKEN);

        self::assertNull($claims);
        self::assertSame([Logger::LEVEL_WARNING], $log->levelsOf(WsTokenService::class));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenClaims(): array
    {
        return [
            'не JSON' => ['{"userId":7,'],
            'JSON не объект' => ['"session-of-anna"'],
            'нет sessionId' => ['{"userId":7}'],
            'userId строкой' => ['{"userId":"7","sessionId":"session-of-anna"}'],
        ];
    }

    #[TestDox('UNIT-TOK-06: токен истёк — Redis сам удалил ключ по TTL, GETDEL пуст → null')]
    public function testExpiredTokenGivesNull(): void
    {
        $redis = self::createStub(RedisClientInterface::class);
        $redis->method('getDel')->willReturn(null);

        $claims = (new WsTokenService($redis))->consume(self::TOKEN);

        self::assertNull($claims);
    }
}
