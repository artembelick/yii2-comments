<?php

declare(strict_types=1);

namespace common\security;

use common\events\EventPublisherInterface;
use common\events\EventPublishException;
use common\redis\RedisUnavailableException;
use Yii;

/**
 * Сообщает WS-серверу, что доступ закончился (ADR 0002): по сообщению в ws.control он закрывает с 4403
 * соединения одной сессии (logout) или все соединения админа (блокировка, смена пароля). Ещё не использованные
 * токены WS завершённой сессии гаснут; токены админа после смены пароля отклоняет проверка auth_key на WS-сервере.
 * Сбой Redis действие не отменяет. При logout ошибка уходит в лог: пользователь всё равно вышел. При отзыве доступа
 * исключение выходит наружу: команду блокировки или смены пароля запускает оператор, и он должен узнать, что
 * WS-сервер об этом не узнал (открытые соединения тогда закроет перепроверка доступа на ping, ADR 0002).
 */
final readonly class SessionTerminator
{
    public function __construct(
        private EventPublisherInterface $events,
        private WsTokenService $tokens,
    ) {}

    public function sessionEnded(string $sessionId): void
    {
        try {
            $this->tokens->endSession($sessionId);
        } catch (RedisUnavailableException $redisUnavailableException) {
            Yii::error(sprintf('Токены WS сессии %s не погашены: %s', $sessionId, $redisUnavailableException->getMessage()), self::class);
        }

        try {
            $this->events->publish(EventPublisherInterface::CONTROL, ['type' => 'session.ended', 'sessionId' => $sessionId]);
        } catch (EventPublishException $eventPublishException) {
            Yii::error(sprintf('WS не узнал о завершении сессии %s: %s', $sessionId, $eventPublishException->getMessage()), self::class);
        }
    }

    /**
     * @throws EventPublishException сообщение не опубликовано: WS-сервер об отзыве не узнал
     */
    public function accessRevoked(int $userId): void
    {
        $this->events->publish(EventPublisherInterface::CONTROL, ['type' => 'access.revoked', 'userId' => $userId]);
    }
}
