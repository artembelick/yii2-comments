<?php

declare(strict_types=1);

namespace common\repositories;

use common\models\User;
use DateTimeImmutable;
use DateTimeInterface;
use Override;
use yii\db\Expression;

final class ActiveRecordUserRepository implements UserRepositoryInterface
{
    #[Override]
    public function findActiveById(int $id): ?User
    {
        return User::findOne(['id' => $id, 'status' => User::STATUS_ACTIVE]);
    }

    #[Override]
    public function findByUsername(string $username): ?User
    {
        return User::find()->where(['=', new Expression('lower(username)'), mb_strtolower($username)])->one();
    }

    #[Override]
    public function create(string $username, string $passwordHash, string $authKey, DateTimeImmutable $now): User
    {
        $user = new User();
        $user->username = $username;
        $user->password_hash = $passwordHash;
        $user->auth_key = $authKey;
        $user->status = User::STATUS_ACTIVE;
        $user->created_at = $now->format(DateTimeInterface::ATOM);
        $user->updated_at = $now->format(DateTimeInterface::ATOM);
        $user->save(false);

        return $user;
    }

    #[Override]
    public function setStatus(int $id, int $status, string $authKey, DateTimeImmutable $now): void
    {
        User::updateAll(
            ['status' => $status, 'auth_key' => $authKey, 'updated_at' => $now->format(DateTimeInterface::ATOM)],
            ['id' => $id],
        );
    }

    #[Override]
    public function setPassword(int $id, string $passwordHash, string $authKey, DateTimeImmutable $now): void
    {
        User::updateAll(
            ['password_hash' => $passwordHash, 'auth_key' => $authKey, 'updated_at' => $now->format(DateTimeInterface::ATOM)],
            ['id' => $id],
        );
    }
}
