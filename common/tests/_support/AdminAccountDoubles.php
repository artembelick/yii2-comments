<?php

declare(strict_types=1);

namespace common\tests\_support;

use common\events\EventPublisherInterface;
use common\models\User;
use common\redis\RedisClientInterface;
use common\repositories\UserRepositoryInterface;
use common\security\SessionTerminator;
use common\security\WsTokenService;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Границы учётных записей админов для unit-тестов сервиса и команд: репозиторий, который находит по логину
 * заданного админа, админ Анна и настоящий SessionTerminator на заглушках публикатора и Redis.
 */
trait AdminAccountDoubles
{
    private const int ANNA_ID = 7;

    private const string OLD_AUTH_KEY = 'old-auth-key-of-anna-0123456789a';

    /**
     * @return UserRepositoryInterface&MockObject
     */
    private function repositoryWith(?User $admin): UserRepositoryInterface
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findByUsername')->willReturn($admin);

        return $users;
    }

    private function anna(): User
    {
        return new User(['id' => self::ANNA_ID, 'username' => 'anna', 'auth_key' => self::OLD_AUTH_KEY, 'status' => User::STATUS_ACTIVE]);
    }

    /** Без своего публикатора события ws.control уходят в заглушку, без своего Redis метки сессий тоже. */
    private function sessionTerminator(?EventPublisherInterface $events = null, ?RedisClientInterface $redis = null): SessionTerminator
    {
        return new SessionTerminator(
            $events ?? self::createStub(EventPublisherInterface::class),
            new WsTokenService($redis ?? self::createStub(RedisClientInterface::class)),
        );
    }
}
