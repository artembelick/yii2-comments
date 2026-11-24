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
}
