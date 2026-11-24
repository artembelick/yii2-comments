<?php

declare(strict_types=1);

namespace common\security;

use common\redis\RedisClientInterface;
use common\redis\RedisUnavailableException;
use Yii;

/**
 * Одноразовый токен для авторизации админки по WebSocket (ADR 0002): случайная строка в Redis
 * под префиксом ws-token: с TTL 60 секунд. Админка получает его у бэкенда, WS-сервер гасит при использовании.
 */
final readonly class WsTokenService
{
    private const string PREFIX = 'ws-token:';

    private const int TTL_SECONDS = 60;

    private const int RANDOM_BYTES = 32;

    /** 32 байта в base64url без «=» — ровно 43 символа. */
    private const string FORMAT = '~^[A-Za-z0-9_-]{43}$~D';

    public function __construct(private RedisClientInterface $redis) {}

    /**
     * @throws RedisUnavailableException
     */
    public function issue(int $userId, string $sessionId): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(self::RANDOM_BYTES)), '+/', '-_'), '=');
        $claims = json_encode(['userId' => $userId, 'sessionId' => $sessionId], JSON_THROW_ON_ERROR);
        $this->redis->setNxEx(self::PREFIX . $token, $claims, self::TTL_SECONDS);

        return $token;
    }

    /**
     * Гасит токен: GETDEL, второй раз тот же токен уже ничего не даст. Строка не того формата до Redis не доходит.
     *
     * @throws RedisUnavailableException
     */
    public function consume(string $token): ?WsTokenClaims
    {
        if (preg_match(self::FORMAT, $token) !== 1) {
            return null;
        }

        $stored = $this->redis->getDel(self::PREFIX . $token);

        return $stored === null ? null : $this->parse($stored);
    }

    private function parse(string $stored): ?WsTokenClaims
    {
        $claims = json_decode($stored, true);
        if (!is_array($claims) || !is_int($claims['userId'] ?? null) || !is_string($claims['sessionId'] ?? null)) {
            Yii::warning('В Redis под токеном WS не claims: запись пропущена', self::class);

            return null;
        }

        return new WsTokenClaims($claims['userId'], $claims['sessionId']);
    }
}
