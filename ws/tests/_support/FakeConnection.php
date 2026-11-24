<?php

declare(strict_types=1);

namespace ws\tests\_support;

use Override;
use ws\ConnectionInterface;

/** Соединение для unit-тестов: запоминает отправленные сообщения, код закрытия и отказ в handshake. */
final class FakeConnection implements ConnectionInterface
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public ?int $closedWith = null;

    public bool $rejected = false;

    public function __construct(private readonly string $id) {}

    #[Override]
    public function id(): string
    {
        return $this->id;
    }

    #[Override]
    public function send(array $message): void
    {
        $this->sent[] = $message;
    }

    #[Override]
    public function close(int $code): void
    {
        $this->closedWith = $code;
    }

    #[Override]
    public function reject(): void
    {
        $this->rejected = true;
    }
}
