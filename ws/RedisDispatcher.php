<?php

declare(strict_types=1);

namespace ws;

use common\events\EventPublisherInterface;
use Throwable;
use Yii;

/**
 * Сообщения из Redis pub/sub → соединения процесса (ADR 0002). Событие комментария уходит как есть
 * всем авторизованным соединениям: неавторизованные событий не видят.
 */
final readonly class RedisDispatcher
{
    private const array COMMENT_EVENTS = ['comment.created', 'comment.updated', 'comment.deleted', 'comment.restored'];

    public function __construct(private ConnectionRegistry $registry) {}

    public function dispatch(string $channel, string $payload): void
    {
        /** @var array<string, mixed>|scalar|null $message JSON-объект события или что угодно другое */
        $message = json_decode($payload, true);
        if ($channel !== EventPublisherInterface::COMMENTS || !is_array($message) || !in_array($message['type'] ?? null, self::COMMENT_EVENTS, true)) {
            Yii::warning(sprintf('Сообщение из канала %s пропущено: не событие комментария', $channel), self::class);

            return;
        }

        foreach ($this->registry->authenticated() as $connection) {
            $this->deliver($connection, $message);
        }
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
