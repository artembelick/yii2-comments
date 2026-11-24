<?php

declare(strict_types=1);

namespace ws;

use common\repositories\UserRepositoryInterface;
use yii\db\Connection;
use yii\db\Exception;

/**
 * Действует ли ещё доступ админа — проверка после погашения токена и на каждом ping (ADR 0002): токен, выданный
 * до блокировки или до смены пароля (auth_key другой), не должен открыть соединение, а открытое не должно жить после них. ws-процесс живёт долго, и соединение с Postgres может оборваться:
 * тогда оно закрывается, Yii открывает его заново на следующем запросе, и проверка повторяется один раз.
 */
final readonly class ActiveAdminChecker
{
    public function __construct(
        private UserRepositoryInterface $users,
        private Connection $db,
    ) {}

    /**
     * Админ активен и его auth_key тот же, что при выдаче токена.
     *
     * @throws AdminCheckUnavailableException БД недоступна и после переподключения
     */
    public function hasValidAccess(int $userId, string $authKey): bool
    {
        try {
            return $this->check($userId, $authKey);
        } catch (Exception) {
            $this->db->close();
        }

        try {
            return $this->check($userId, $authKey);
        } catch (Exception $exception) {
            throw new AdminCheckUnavailableException('БД не ответила на проверку админа', 0, $exception);
        }
    }

    /**
     * @throws Exception
     */
    private function check(int $userId, string $authKey): bool
    {
        return $this->users->findActiveById($userId)?->validateAuthKey($authKey) === true;
    }
}
