<?php

declare(strict_types=1);

namespace ws;

/** Коды закрытия WS (ADR 0002, docs/test-plans/regression.md, «Коды закрытия»). */
final class CloseCode
{
    /** Тишина дольше таймаута: клиент пропал, не попрощавшись. */
    public const int GOING_AWAY = 1001;

    /** Сервер не смог проверить авторизацию: клиент переподключится с backoff. */
    public const int INTERNAL_ERROR = 1011;

    public const int PROTOCOL_VIOLATION = 4400;

    /** Нет авторизации или токен неверен: клиент берёт новый токен. */
    public const int UNAUTHORIZED = 4401;

    /** Сессия завершена (logout, блокировка, смена пароля): клиент уходит на логин без переподключения. */
    public const int SESSION_ENDED = 4403;
}
