<?php

declare(strict_types=1);

namespace backend\forms;

use common\models\User;
use common\repositories\UserRepositoryInterface;
use common\security\RateLimiterInterface;
use common\security\RateLimiterUnavailableException;
use Override;
use Yii;
use yii\base\Model;
use yii\web\Session;
use yii\web\User as WebUser;

/**
 * Вход админа. Защита от подбора: 5 попыток за 15 минут на пару «логин в нижнем регистре + IP»,
 * успешный вход обнуляет счётчик. Попытка засчитывается до проверки пароля, и решение принимается
 * по значению счётчика после неё: так параллельные запросы не проскакивают мимо лимита.
 * Если счётчики недоступны, вход отказывает: без лимита подбор не ограничен.
 */
final class LoginForm extends Model
{
    /** bcrypt того же cost, что у настоящих хэшей: несуществующий логин проверяется так же долго, как существующий. */
    public const string DUMMY_HASH = '$2y$13$80c4H9XgHVB0FFGdJ1GiyeeltehmnnFaJ.rc7n1gdA4WaceZV8vDy';

    private const int ATTEMPTS = 5;

    private const int WINDOW_SECONDS = 900;

    /** Ключ лимита: логин в нижнем регистре и IP. */
    private const string LIMIT_KEY = 'login:%s:%s';

    /** Ключ сессии с auth_key админа на момент входа. */
    public const string SESSION_AUTH_KEY = 'auth_key';

    public string $username = '';

    public string $password = '';

    public function __construct(
        private readonly WebUser $webUser,
        private readonly Session $session,
        private readonly UserRepositoryInterface $users,
        private readonly RateLimiterInterface $limiter,
    ) {
        parent::__construct();
    }

    #[Override]
    public function rules(): array
    {
        return [
            ['username', 'required', 'message' => Yii::t('app', 'login.username_required')],
            ['password', 'required', 'message' => Yii::t('app', 'login.password_required')],
        ];
    }

    #[Override]
    public function attributeLabels(): array
    {
        return [
            'username' => Yii::t('app', 'login.username'),
            'password' => Yii::t('app', 'login.password'),
        ];
    }

    public function login(string $ip): LoginResult
    {
        if (!$this->validate()) {
            return LoginResult::Invalid;
        }

        $key = sprintf(self::LIMIT_KEY, mb_strtolower($this->username), $ip);

        try {
            return $this->attempt($key);
        } catch (RateLimiterUnavailableException $rateLimiterUnavailableException) {
            Yii::error('Вход отклонён: счётчики лимита недоступны. ' . $rateLimiterUnavailableException->getMessage(), __METHOD__);
            $this->addError('password', Yii::t('app', 'login.unavailable'));

            return LoginResult::Unavailable;
        }
    }

    private function attempt(string $key): LoginResult
    {
        if (!$this->limiter->hit($key, self::ATTEMPTS, self::WINDOW_SECONDS)) {
            $this->addError('password', Yii::t('app', 'login.too_many_attempts'));

            return LoginResult::TooManyAttempts;
        }

        $admin = $this->users->findByUsername($this->username);
        $passwordMatches = $this->passwordMatches($admin);
        if (!$admin instanceof User || !$passwordMatches || $admin->isBlocked()) {
            $this->addError('password', Yii::t('app', 'login.invalid'));

            return LoginResult::Invalid;
        }

        $this->limiter->reset($key);
        $this->webUser->login($admin);
        $this->session->set(self::SESSION_AUTH_KEY, $admin->getAuthKey());

        return LoginResult::Success;
    }

    /** Пароль проверяется всегда, даже без админа: время ответа не выдаёт, существует ли логин. */
    private function passwordMatches(?User $admin): bool
    {
        if (!$admin instanceof User) {
            Yii::$app->getSecurity()->validatePassword($this->password, self::DUMMY_HASH);

            return false;
        }

        return $admin->validatePassword($this->password);
    }
}
