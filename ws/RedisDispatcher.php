<?php

declare(strict_types=1);

namespace ws;

use common\events\EventPublisherInterface;
use Throwable;
use Yii;

/**
 * Сообщения из Redis pub/sub → соединения процесса (ADR 0002). Событие комментария уходит как есть
 * всем авторизованным соединениям: неавторизованные событий не видят. Управляющее сообщение из ws.control
 * закрывает с 4403 соединения завершённой сессии (session.ended) или все соединения админа (access.revoked).
 */
final readonly class RedisDispatcher
{
    private const array COMMENT_EVENTS = ['comment.created', 'comment.updated', 'comment.deleted', 'comment.restored'];

    public function __construct(private ConnectionRegistry $registry) {}

    public function dispatch(string $channel, string $payload): void
    {
        /** @var array<string, mixed>|scalar|null $decoded JSON-объект сообщения или что угодно другое */
        $decoded = json_decode($payload, true);
        $message = is_array($decoded) ? $decoded : [];
        $handled = match ($channel) {
            EventPublisherInterface::COMMENTS => $this->broadcast($message),
            EventPublisherInterface::CONTROL => $this->terminate($message),
            default => false,
        };
        if (!$handled) {
            Yii::warning(sprintf('Сообщение из канала %s пропущено: непонятный тип или поля', $channel), self::class);
        }
    }

    /**
     * Событие комментария — всем авторизованным соединениям.
     *
     * @param array<string, mixed> $message
     *
     * @return bool false — не событие комментария
     */
    private function broadcast(array $message): bool
    {
        if (!in_array($message['type'] ?? null, self::COMMENT_EVENTS, true)) {
            return false;
        }

        foreach ($this->registry->authenticated() as $connection) {
            $this->deliver($connection, $message);
        }

        return true;
    }

    /**
     * Управляющее сообщение: закрыть с 4403 соединения сессии или админа.
     *
     * @param array<string, mixed> $message
     *
     * @return bool false — непонятное сообщение
     */
    private function terminate(array $message): bool
    {
        $type = $message['type'] ?? null;
        $sessionId = $message['sessionId'] ?? null;
        $userId = $message['userId'] ?? null;
        $connections = match (true) {
            $type === 'session.ended' && is_string($sessionId) => $this->registry->bySession($sessionId),
            $type === 'access.revoked' && is_int($userId) => $this->registry->byUser($userId),
            default => null,
        };
        if ($connections === null) {
            return false;
        }

        foreach ($connections as $connection) {
            $connection->close(CloseCode::SESSION_ENDED);
        }

        return true;
    }

    /**
     * Сбой одного соединения не останавливает рассылку остальным. Упавшее закрывается: события, которые
     * оно пропустило, клиент восполнит, перечитав список после переподключения.
     *
     * @param array<string, mixed> $message
     */
    private function deliver(ConnectionInterface $connection, array $message): void
    {
        try {
            $connection->send($message);
        } catch (Throwable $throwable) {
            Yii::warning(sprintf('Соединение %s закрыто: событие не отправлено (%s)', $connection->id(), $throwable->getMessage()), self::class);
            $connection->close(CloseCode::INTERNAL_ERROR);
        }
    }
}
