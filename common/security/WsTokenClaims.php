<?php

declare(strict_types=1);

namespace common\security;

/** Кому выдан токен WS: админ и его сессия. По сессии WS-сервер закрывает соединения при logout. */
final readonly class WsTokenClaims
{
    public function __construct(
        public int $userId,
        public string $sessionId,
    ) {}
}
