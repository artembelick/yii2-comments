<?php

declare(strict_types=1);

namespace ws\runtime;

use Override;
use Workerman\Connection\TcpConnection;
use ws\ConnectionInterface;

/** Соединение Workerman для ws\MessageHandler. Логики нет: entrypoint-limits, покрытие — smoke, e2e и REG-WS. */
final readonly class WorkermanConnection implements ConnectionInterface
{
    /** Первый байт кадра close (FIN + opcode 8): Websocket::encode берёт его из websocketType. */
    private const string CLOSE_FRAME = "\x88";

    private const string FORBIDDEN = "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";

    public function __construct(private TcpConnection $connection) {}

    #[Override]
    public function id(): string
    {
        return (string) $this->connection->id;
    }

    #[Override]
    public function send(array $message): void
    {
        $this->connection->send(json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    #[Override]
    public function close(int $code): void
    {
        $this->connection->websocketType = self::CLOSE_FRAME;
        $this->connection->close(pack('n', $code));
    }

    #[Override]
    public function reject(): void
    {
        $this->connection->close(self::FORBIDDEN, true);
    }
}
