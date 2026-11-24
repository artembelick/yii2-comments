<?php

declare(strict_types=1);

namespace ws;

use common\redis\RedisUnavailableException;
use common\security\WsTokenClaims;
use common\security\WsTokenService;

/**
 * Протокол WS-сервера (ADR 0002, docs/test-plans/regression.md, «WS-контракты»): handshake только с Origin админки,
 * первым сообщением {type: "auth", token}, затем {type: "ping"}. На любое нарушение соединение закрывается своим кодом.
 */
final class MessageHandler
{
    private const float AUTH_TIMEOUT = 5.0;

    private const float SILENCE_TIMEOUT = 60.0;

    private const int MAX_MESSAGE_BYTES = 4096;

    /** @var array<string, array{auth: int, silence: int}> */
    private array $timers = [];

    public function __construct(
        private readonly ConnectionRegistry $registry,
        private readonly WsTokenService $tokens,
        private readonly ActiveAdminChecker $admins,
        private readonly TimerInterface $timer,
        private readonly OriginPolicy $origins,
    ) {}

    /** Handshake: чужой Origin получает 403, свой — 5 секунд на авторизацию. */
    public function onConnect(ConnectionInterface $connection, ?string $origin): void
    {
        if (!$this->origins->isAllowed($origin)) {
            $connection->reject();

            return;
        }

        $this->registry->add($connection);
        $this->timers[$connection->id()] = [
            'auth' => $this->timer->after(self::AUTH_TIMEOUT, static fn() => $connection->close(CloseCode::UNAUTHORIZED)),
            'silence' => $this->silenceTimer($connection),
        ];
    }

    public function onMessage(ConnectionInterface $connection, string $raw): void
    {
        $this->restartSilenceTimer($connection);
        $type = strlen($raw) > self::MAX_MESSAGE_BYTES ? null : $this->typeOf($raw);
        match ($type) {
            'auth' => $this->authenticate($connection, $raw),
            'ping' => $this->pong($connection),
            default => $connection->close(CloseCode::PROTOCOL_VIOLATION),
        };
    }

    public function onClose(ConnectionInterface $connection): void
    {
        $this->registry->remove($connection);
        array_map($this->timer->cancel(...), $this->timers[$connection->id()] ?? []);
        unset($this->timers[$connection->id()]);
    }

    private function authenticate(ConnectionInterface $connection, string $raw): void
    {
        if ($this->registry->isAuthenticated($connection)) {
            $connection->close(CloseCode::PROTOCOL_VIOLATION);

            return;
        }

        try {
            $claims = $this->tokens->consume($this->tokenOf($raw));
            $active = $claims instanceof WsTokenClaims && $this->admins->isActive($claims->userId);
        } catch (AdminCheckUnavailableException|RedisUnavailableException) {
            $connection->close(CloseCode::INTERNAL_ERROR);

            return;
        }

        if (!$active) {
            $connection->close(CloseCode::UNAUTHORIZED);

            return;
        }

        $this->registry->authenticate($connection, $claims->userId, $claims->sessionId);
        $this->timer->cancel($this->timers[$connection->id()]['auth'] ?? 0);
        $connection->send(['type' => 'auth.ok']);
    }

    private function pong(ConnectionInterface $connection): void
    {
        if (!$this->registry->isAuthenticated($connection)) {
            $connection->close(CloseCode::PROTOCOL_VIOLATION);

            return;
        }

        $connection->send(['type' => 'pong']);
    }

    private function restartSilenceTimer(ConnectionInterface $connection): void
    {
        $timers = $this->timers[$connection->id()] ?? ['auth' => 0, 'silence' => 0];
        $this->timer->cancel($timers['silence']);
        $this->timers[$connection->id()] = ['auth' => $timers['auth'], 'silence' => $this->silenceTimer($connection)];
    }

    private function silenceTimer(ConnectionInterface $connection): int
    {
        return $this->timer->after(self::SILENCE_TIMEOUT, static fn() => $connection->close(CloseCode::GOING_AWAY));
    }

    /** Поле type JSON-объекта; не объект или type не строка — null. */
    private function typeOf(string $raw): ?string
    {
        $message = json_decode($raw, true);

        return is_array($message) && is_string($message['type'] ?? null) ? $message['type'] : null;
    }

    private function tokenOf(string $raw): string
    {
        /** @var array{token?: mixed} $message */
        $message = json_decode($raw, true);

        return is_string($message['token'] ?? null) ? $message['token'] : '';
    }
}
