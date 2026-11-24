<?php

declare(strict_types=1);

namespace common\services;

use common\events\EventPublishException;
use common\models\User;
use common\repositories\UserRepositoryInterface;
use common\security\SessionTerminator;
use Psr\Clock\ClockInterface;
use Yii;

/**
 * Учётные записи админов. Админов создают, блокируют и меняют им пароль консольными командами,
 * регистрации в админке нет. Блокировка и смена пароля завершают доступ сразу: HTTP-сессии админа
 * гаснут на следующем запросе (findIdentity и проверка auth_key в yii\web\User), WS-соединения
 * закрываются по accessRevoked.
 */
final readonly class AdminAccountService
{
    private const string USERNAME_PATTERN = '/^[A-Za-z0-9_.-]{3,64}$/';

    private const int PASSWORD_MIN_LENGTH = 12;

    private const int AUTH_KEY_LENGTH = 32;

    public function __construct(
        private UserRepositoryInterface $users,
        private ClockInterface $clock,
        private SessionTerminator $terminator,
    ) {}

    /**
     * Создаёт активного админа.
     *
     * @throws InvalidAdminDataException логин не подходит или занят, пароль короче 12 символов
     */
    public function create(string $username, string $password): User
    {
        $errors = [];
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            $errors[] = Yii::t('app', 'cli.admin.username_invalid');
        } elseif ($this->users->findByUsername($username) instanceof User) {
            $errors[] = Yii::t('app', 'cli.admin.username_taken', ['username' => $username]);
        }

        if (mb_strlen($password) < self::PASSWORD_MIN_LENGTH) {
            $errors[] = Yii::t('app', 'cli.admin.password_too_short', ['min' => self::PASSWORD_MIN_LENGTH]);
        }

        if ($errors !== []) {
            throw new InvalidAdminDataException($errors);
        }

        return $this->users->create(
            $username,
            Yii::$app->getSecurity()->generatePasswordHash($password),
            $this->newAuthKey(),
            $this->clock->now(),
        );
    }

    /**
     * Блокирует админа и меняет ему auth_key: даже сессия, в которую во время блокировки никто не заходил,
     * после unblock не оживёт.
     *
     * @throws AdminNotFoundException
     * @throws EventPublishException админ заблокирован, но WS-сервер об этом не узнал
     */
    public function block(string $username): void
    {
        $admin = $this->find($username);
        $this->users->setStatus($admin->id, User::STATUS_BLOCKED, $this->newAuthKey(), $this->clock->now());
        $this->terminator->accessRevoked($admin->id);
    }

    /**
     * Снимает блокировку. Ключ тоже новый: админ входит заново, прежние сессии не действуют.
     *
     * @throws AdminNotFoundException
     */
    public function unblock(string $username): void
    {
        $this->users->setStatus($this->find($username)->id, User::STATUS_ACTIVE, $this->newAuthKey(), $this->clock->now());
    }

    /**
     * Новый пароль и новый auth_key: все прежние сессии админа перестают действовать.
     *
     * @throws AdminNotFoundException
     * @throws InvalidAdminDataException пароль короче 12 символов
     * @throws EventPublishException пароль изменён, но WS-сервер об этом не узнал
     */
    public function changePassword(string $username, string $password): void
    {
        $admin = $this->find($username);
        if (mb_strlen($password) < self::PASSWORD_MIN_LENGTH) {
            throw new InvalidAdminDataException([Yii::t('app', 'cli.admin.password_too_short', ['min' => self::PASSWORD_MIN_LENGTH])]);
        }

        $this->users->setPassword(
            $admin->id,
            Yii::$app->getSecurity()->generatePasswordHash($password),
            $this->newAuthKey(),
            $this->clock->now(),
        );
        $this->terminator->accessRevoked($admin->id);
    }

    private function newAuthKey(): string
    {
        return Yii::$app->getSecurity()->generateRandomString(self::AUTH_KEY_LENGTH);
    }

    /**
     * @throws AdminNotFoundException
     */
    private function find(string $username): User
    {
        return $this->users->findByUsername($username)
            ?? throw new AdminNotFoundException(Yii::t('app', 'cli.admin.not_found', ['username' => $username]));
    }
}
