<?php

declare(strict_types=1);

namespace common\services;

use common\models\User;
use common\repositories\UserRepositoryInterface;
use Psr\Clock\ClockInterface;
use Yii;

/** Учётные записи админов. Админов создают консольной командой, регистрации в админке нет. */
final readonly class AdminAccountService
{
    private const string USERNAME_PATTERN = '/^[A-Za-z0-9_.-]{3,64}$/';

    private const int PASSWORD_MIN_LENGTH = 12;

    private const int AUTH_KEY_LENGTH = 32;

    public function __construct(
        private UserRepositoryInterface $users,
        private ClockInterface $clock,
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

        $security = Yii::$app->getSecurity();

        return $this->users->create(
            $username,
            $security->generatePasswordHash($password),
            $security->generateRandomString(self::AUTH_KEY_LENGTH),
            $this->clock->now(),
        );
    }
}
