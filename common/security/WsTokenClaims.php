<?php

declare(strict_types=1);

namespace common\security;

/**
 * Кому выдан токен WS: админ, его сессия и auth_key админа на момент выдачи. По сессии WS-сервер закрывает
 * соединения при logout, по auth_key отклоняет токен, выданный до смены пароля.
 */
final readonly class WsTokenClaims
{
    public function __construct(
        public int $userId,
        public string $sessionId,
        public string $authKey,
    ) {}
}
