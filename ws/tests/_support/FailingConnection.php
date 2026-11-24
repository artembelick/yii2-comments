<?php

declare(strict_types=1);

namespace ws\tests\_support;

use Override;
use RuntimeException;
use ws\ConnectionInterface;

/** Соединение для unit-тестов, которое падает на send: например, клиент уже отвалился. Код закрытия запоминается. */
final class FailingConnection implements ConnectionInterface
{
    public ?int $closedWith = null;

    public function __construct(private readonly string $id) {}

    #[Override]
    public function id(): string
    {
        return $this->id;
    }

    #[Override]
    public function send(array $message): never
    {
        throw new RuntimeException('Соединение ' . $this->id . ' не принимает данные');
    }

    #[Override]
    public function close(int $code): void
    {
        $this->closedWith = $code;
    }

    #[Override]
    public function reject(): void {}
}
