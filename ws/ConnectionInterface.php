<?php

declare(strict_types=1);

namespace ws;

/** Соединение WS-сервера. Реализация над Workerman живёт в ws/runtime, unit-тесты подставляют фейк. */
interface ConnectionInterface
{
    public function id(): string;

    /**
     * Отправляет сообщение JSON-текстом.
     *
     * @param array<string, mixed> $message
     */
    public function send(array $message): void;

    /** Закрывает соединение кадром close со своим кодом (ADR 0002: 4400, 4401, 4403, 1001, 1011). */
    public function close(int $code): void;

    /** Отклоняет handshake ответом HTTP 403: соединение не открывается. */
    public function reject(): void;
}
