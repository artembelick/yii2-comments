<?php

declare(strict_types=1);

namespace common\models;

use common\repositories\UserRepositoryInterface;
use Override;
use Yii;
use yii\base\NotSupportedException;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;

/**
 * Админ: учётная запись для входа в админку (GLOSSARY.md). Админов не удаляют, только блокируют.
 *
 * @property int $id
 * @property string $username
 * @property string $password_hash
 * @property string $auth_key
 * @property int $status
 * @property string $created_at
 * @property string $updated_at
 */
final class User extends ActiveRecord implements IdentityInterface
{
    public const int STATUS_ACTIVE = 10;

    public const int STATUS_BLOCKED = 0;

    #[Override]
    public static function tableName(): string
    {
        return '{{%user}}';
    }

    /**
     * Колонки перечислены явно: модель собирается без чтения схемы БД, например в unit-тестах веб-слоя.
     * Новая колонка таблицы user добавляется сюда вместе с миграцией.
     *
     * @return list<string>
     */
    #[Override]
    public function attributes(): array
    {
        return ['id', 'username', 'password_hash', 'auth_key', 'status', 'created_at', 'updated_at'];
    }

    /**
     * Только активные: заблокированный админ теряет сессию на следующем запросе.
     *
     * @param int|string $id
     */
    #[Override]
    public static function findIdentity($id): ?self
    {
        return Yii::$container->get(UserRepositoryInterface::class)->findActiveById((int) $id);
    }

    /**
     * @param mixed $token
     * @param mixed $type
     */
    #[Override]
    public static function findIdentityByAccessToken($token, $type = null): never
    {
        throw new NotSupportedException('Вход по токену доступа не поддерживается.');
    }

    #[Override]
    public function getId(): int
    {
        return $this->id;
    }

    #[Override]
    public function getAuthKey(): string
    {
        return $this->auth_key;
    }

    /**
     * Сравнение за постоянное время: по времени ответа ключ не подобрать.
     *
     * @param string $authKey
     */
    #[Override]
    public function validateAuthKey($authKey): bool
    {
        return hash_equals($this->auth_key, $authKey);
    }

    public function validatePassword(string $password): bool
    {
        return Yii::$app->getSecurity()->validatePassword($password, $this->password_hash);
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }
}
