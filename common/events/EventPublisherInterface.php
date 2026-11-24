<?php

declare(strict_types=1);

namespace common\events;

/**
 * Публикация событий для WS-сервера (ADR 0002). Каналы: comments.events — события комментариев,
 * ws.control — управляющие сообщения для WS.
 */
interface EventPublisherInterface
{
    public const string COMMENTS = 'comments.events';

    public const string CONTROL = 'ws.control';

    /**
     * @param array<string, mixed> $message
     *
     * @throws EventPublishException событие не опубликовано
     */
    public function publish(string $channel, array $message): void;
}
