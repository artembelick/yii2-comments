<?php

declare(strict_types=1);

namespace ws;

use common\models\User;
use common\repositories\UserRepositoryInterface;
use yii\db\Connection;
use yii\db\Exception;

/**
 * Активен ли админ — проверка после погашения токена (ADR 0002): токен, выданный до блокировки,
 * не должен открыть соединение. ws-процесс живёт долго, и соединение с Postgres может оборваться:
 * тогда оно закрывается, Yii открывает его заново на следующем запросе, и проверка повторяется один раз.
 */
final readonly class ActiveAdminChecker
{
    public function __construct(
        private UserRepositoryInterface $users,
        private Connection $db,
    ) {}

    /**
     * @throws AdminCheckUnavailableException БД недоступна и после переподключения
     */
    public function isActive(int $userId): bool
    {
        try {
            return $this->users->findActiveById($userId) instanceof User;
        } catch (Exception) {
            $this->db->close();
        }

        try {
            return $this->users->findActiveById($userId) instanceof User;
        } catch (Exception $exception) {
            throw new AdminCheckUnavailableException('БД не ответила на проверку админа', 0, $exception);
        }
    }
}
