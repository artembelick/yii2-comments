<?php

declare(strict_types=1);

namespace common\repositories;

use common\models\User;
use DateTimeImmutable;

interface UserRepositoryInterface
{
    /** Активный админ по id; заблокированный и несуществующий — null. */
    public function findActiveById(int $id): ?User;

    /** Админ по логину без учёта регистра, с любым статусом. */
    public function findByUsername(string $username): ?User;

    /** Новый активный админ. Логин, занятый в любом регистре, отклоняет уникальный индекс БД. */
    public function create(string $username, string $passwordHash, string $authKey, DateTimeImmutable $now): User;

    /**
     * Статус админа (User::STATUS_*) вместе с новым auth_key и время изменения. С новым ключом прежние HTTP-сессии
     * и ещё не использованные токены WS не действуют, даже если статус потом вернут.
     */
    public function setStatus(int $id, int $status, string $authKey, DateTimeImmutable $now): void;

    /** Новый хэш пароля вместе с новым auth_key: старые HTTP-сессии админа перестают проходить проверку ключа. */
    public function setPassword(int $id, string $passwordHash, string $authKey, DateTimeImmutable $now): void;
}
