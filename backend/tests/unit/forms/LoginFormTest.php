<?php

declare(strict_types=1);

namespace backend\tests\unit\forms;

use backend\forms\LoginForm;
use backend\forms\LoginResult;
use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\models\User;
use common\repositories\UserRepositoryInterface;
use common\security\RateLimiterInterface;
use common\security\RateLimiterUnavailableException;
use Override;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use Yii;
use yii\base\Security;
use yii\log\Logger;
use yii\web\Session;
use yii\web\User as WebUser;

final class LoginFormTest extends Unit
{
    private const string IP = '10.0.0.1';

    private const string PASSWORD = 'correct-password';

    private const string AUTH_KEY = 'auth-key-of-anna';

    private MockObject&UserRepositoryInterface $users;

    private MockObject&RateLimiterInterface $limiter;

    private MockObject&WebUser $webUser;

    private MockObject&Session $session;

    #[Override]
    protected function _before(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->limiter = $this->createMock(RateLimiterInterface::class);
        $this->webUser = $this->createMock(WebUser::class);
        $this->session = $this->createMock(Session::class);
    }

    #[TestDox('UNIT-LOGIN-01: пустые поля → ошибки login.username_required и login.password_required, репозиторий не вызывается')]
    public function testEmptyFieldsAreRequired(): void
    {
        $this->users->expects(self::never())->method('findByUsername');
        $form = $this->form('', '');

        $result = $form->login(self::IP);

        self::assertSame(
            [LoginResult::Invalid, ['username' => [Yii::t('app', 'login.username_required')], 'password' => [Yii::t('app', 'login.password_required')]]],
            [$result, $form->getErrors()],
        );
    }

    #[TestDox('UNIT-LOGIN-02: попытка засчитана сверх лимита → TooManyAttempts, пароль не проверяется')]
    public function testExceededLimitStopsBeforePasswordCheck(): void
    {
        $this->limiter->expects(self::once())->method('hit')->with('login:anna:' . self::IP, 5, 900)->willReturn(false);
        $this->users->expects(self::never())->method('findByUsername');
        $form = $this->form('anna', self::PASSWORD);

        $result = $form->login(self::IP);

        self::assertSame([LoginResult::TooManyAttempts, [Yii::t('app', 'login.too_many_attempts')]], [$result, $form->getErrors('password')]);
    }

    #[TestDox('UNIT-LOGIN-03: верные данные активного админа → Success, счётчик сброшен, админ вошёл, auth_key в сессии')]
    public function testValidCredentialsLogIn(): void
    {
        $anna = $this->admin(User::STATUS_ACTIVE);
        $this->users->method('findByUsername')->willReturn($anna);
        $this->limiter->method('hit')->willReturn(true);
        $this->limiter->expects(self::once())->method('reset')->with('login:anna:' . self::IP);
        $this->webUser->expects(self::once())->method('login')->with($anna)->willReturn(true);
        $this->session->expects(self::once())->method('set')->with('auth_key', self::AUTH_KEY);

        $result = $this->form('anna', self::PASSWORD)->login(self::IP);

        self::assertSame(LoginResult::Success, $result);
    }

    #[TestDox('UNIT-LOGIN-04: неверный пароль или заблокированный админ → Invalid, login.invalid, счётчик +1')]
    #[DataProvider('failures')]
    public function testFailureCountsAttempt(int $status, string $password): void
    {
        $this->users->method('findByUsername')->willReturn($this->admin($status));
        $this->limiter->expects(self::once())->method('hit')->with('login:anna:' . self::IP, 5, 900)->willReturn(true);
        $this->limiter->expects(self::never())->method('reset');
        $this->webUser->expects(self::never())->method('login');
        $form = $this->form('anna', $password);

        $result = $form->login(self::IP);

        self::assertSame([LoginResult::Invalid, [Yii::t('app', 'login.invalid')]], [$result, $form->getErrors('password')]);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function failures(): array
    {
        return [
            'неверный пароль' => [User::STATUS_ACTIVE, 'wrong-password'],
            'заблокированный админ' => [User::STATUS_BLOCKED, self::PASSWORD],
        ];
    }

    #[TestDox('UNIT-LOGIN-04: несуществующий логин → Invalid, login.invalid, счётчик +1')]
    public function testUnknownUsernameCountsAttempt(): void
    {
        $this->users->method('findByUsername')->willReturn(null);
        $this->limiter->expects(self::once())->method('hit')->with('login:ghost:' . self::IP, 5, 900)->willReturn(true);
        $form = $this->form('ghost', self::PASSWORD);

        $result = $form->login(self::IP);

        self::assertSame([LoginResult::Invalid, [Yii::t('app', 'login.invalid')]], [$result, $form->getErrors('password')]);
    }

    #[TestDox('UNIT-LOGIN-05: для несуществующего логина пароль всё равно сверяется с фиктивным хэшем')]
    public function testMissingUserStillVerifiesPassword(): void
    {
        $this->limiter->method('hit')->willReturn(true);
        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('validatePassword')->with(self::PASSWORD, LoginForm::DUMMY_HASH)->willReturn(false);
        Yii::$app->set('security', $security);

        $this->form('ghost', self::PASSWORD)->login(self::IP);
    }

    #[TestDox('UNIT-LOGIN-06: логин уходит в репозиторий как есть, ключ лимита — от логина в нижнем регистре')]
    public function testUsernameCaseInRepositoryAndLimitKey(): void
    {
        $this->users->expects(self::once())->method('findByUsername')->with('AnNa')->willReturn(null);
        $this->limiter->expects(self::once())->method('hit')->with('login:anna:' . self::IP, 5, 900)->willReturn(true);

        $this->form('AnNa', self::PASSWORD)->login(self::IP);
    }

    #[TestDox('UNIT-LOGIN-07: счётчики недоступны → Unavailable, login.unavailable, пароль не проверяется, ошибка в логе')]
    public function testUnavailableLimiterRefusesLogin(): void
    {
        $this->limiter->method('hit')->willThrowException(new RateLimiterUnavailableException('redis down'));
        $this->users->expects(self::never())->method('findByUsername');
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('log')->with(self::stringContains('redis down'), Logger::LEVEL_ERROR);
        Yii::setLogger($logger);
        $form = $this->form('anna', self::PASSWORD);

        $result = $form->login(self::IP);

        self::assertSame([LoginResult::Unavailable, [Yii::t('app', 'login.unavailable')]], [$result, $form->getErrors('password')]);
    }

    private function form(string $username, string $password): LoginForm
    {
        $form = new LoginForm($this->webUser, $this->session, $this->users, $this->limiter);
        $form->username = $username;
        $form->password = $password;

        return $form;
    }

    private function admin(int $status): User
    {
        $admin = new User();
        $admin->id = 7;
        $admin->username = 'anna';
        $admin->password_hash = Yii::$app->getSecurity()->generatePasswordHash(self::PASSWORD);
        $admin->auth_key = self::AUTH_KEY;
        $admin->status = $status;

        return $admin;
    }
}
