<?php

declare(strict_types=1);

namespace backend\tests\unit\controllers;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\models\User;
use common\repositories\UserRepositoryInterface;
use common\security\RateLimiterInterface;
use common\security\RateLimiterUnavailableException;
use PHPUnit\Framework\Attributes\TestDox;
use Yii;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

final class AuthControllerTest extends Unit
{
    private const string PASSWORD = 'correct-password';

    private const string HOST = 'http://admin.comments.localhost';

    #[TestDox('UNIT-AUTH-01: GET /login от гостя → форма входа')]
    public function testGuestSeesLoginForm(): void
    {
        Yii::$app->user->setIdentity(null);

        $page = Yii::$app->runAction('auth/login');

        self::assertSame(200, Yii::$app->response->statusCode);
        self::assertIsString($page);
        self::assertStringContainsString(Yii::t('app', 'login.title'), $page);
        self::assertStringContainsString(Yii::t('app', 'login.submit'), $page);
    }

    #[TestDox('UNIT-AUTH-01: GET /login от вошедшего → редирект на список')]
    public function testSignedInAdminIsRedirectedToList(): void
    {
        Yii::$app->user->setIdentity($this->admin());

        $response = Yii::$app->runAction('auth/login');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame([302, self::HOST . '/'], [$response->statusCode, $response->headers->get('Location')]);
    }

    #[TestDox('UNIT-AUTH-02: успешный вход → returnUrl, только если это путь этого же хоста')]
    #[DataProvider('returnUrls')]
    public function testSuccessRedirectsToSafeReturnUrl(string $returnUrl, string $expectedLocation): void
    {
        $this->signInWillSucceed();
        Yii::$app->user->setIdentity(null);
        Yii::$app->user->setReturnUrl($returnUrl);
        $this->post(['username' => 'anna', 'password' => self::PASSWORD]);

        $response = Yii::$app->runAction('auth/login');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame([302, $expectedLocation], [$response->statusCode, $response->headers->get('Location')]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function returnUrls(): array
    {
        return [
            'путь правки' => ['/comments/5/edit', self::HOST . '/comments/5/edit'],
            'полный адрес этого хоста' => [self::HOST . '/?status=deleted', self::HOST . '/?status=deleted'],
            'внешний хост' => ['https://evil.example', self::HOST . '/'],
            'протокол-относительный адрес' => ['//evil.example', self::HOST . '/'],
            'javascript:' => ['javascript:alert(1)', self::HOST . '/'],
            'обратный слэш' => ['/\evil.example', self::HOST . '/'],
            'хост с похожим началом' => [self::HOST . '.evil.example/', self::HOST . '/'],
            'табуляция после слэша' => ["/\t/evil.example", self::HOST . '/'],
            'перевод строки после слэша' => ["/\n/evil.example", self::HOST . '/'],
            'управляющий символ в пути' => ["/comments\x00/5", self::HOST . '/'],
        ];
    }

    #[TestDox('UNIT-AUTH-03: Invalid → 422 и форма с ошибкой')]
    public function testInvalidCredentialsAnswer422(): void
    {
        Yii::$container->set(UserRepositoryInterface::class, self::createStub(UserRepositoryInterface::class));
        Yii::$container->set(RateLimiterInterface::class, $this->limiterWithinLimit());

        Yii::$app->user->setIdentity(null);
        $this->post(['username' => 'anna', 'password' => 'wrong-password']);

        $page = Yii::$app->runAction('auth/login');

        self::assertSame(422, Yii::$app->response->statusCode);
        self::assertIsString($page);
        self::assertStringContainsString(Yii::t('app', 'login.invalid'), $page);
    }

    #[TestDox('UNIT-AUTH-03: TooManyAttempts → 429 и login.too_many_attempts')]
    public function testTooManyAttemptsAnswer429(): void
    {
        $limiter = self::createStub(RateLimiterInterface::class);
        $limiter->method('hit')->willReturn(false);
        $this->signInWith($limiter);

        $page = Yii::$app->runAction('auth/login');

        self::assertSame(429, Yii::$app->response->statusCode);
        self::assertIsString($page);
        self::assertStringContainsString(Yii::t('app', 'login.too_many_attempts'), $page);
    }

    #[TestDox('UNIT-AUTH-03: Unavailable → 503 и login.unavailable')]
    public function testUnavailableLimiterAnswers503(): void
    {
        $limiter = self::createStub(RateLimiterInterface::class);
        $limiter->method('hit')->willThrowException(new RateLimiterUnavailableException('redis down'));
        $this->signInWith($limiter);

        $page = Yii::$app->runAction('auth/login');

        self::assertSame(503, Yii::$app->response->statusCode);
        self::assertIsString($page);
        self::assertStringContainsString(Yii::t('app', 'login.unavailable'), $page);
    }

    #[TestDox('UNIT-AUTH-05: logout через GET → 405')]
    public function testLogoutRejectsGet(): void
    {
        Yii::$app->user->setIdentity($this->admin());

        $this->expectException(MethodNotAllowedHttpException::class);

        Yii::$app->runAction('auth/logout');
    }

    #[TestDox('UNIT-AUTH-05: logout от гостя → редирект на логин')]
    public function testLogoutRequiresSignedInAdmin(): void
    {
        Yii::$app->user->setIdentity(null);
        $this->post([]);

        Yii::$app->runAction('auth/logout');

        self::assertSame([302, self::HOST . '/login'], [Yii::$app->response->statusCode, Yii::$app->response->headers->get('Location')]);
    }

    #[TestDox('UNIT-AUTH-05: logout через POST от вошедшего → выход и редирект на логин')]
    public function testLogoutSignsOut(): void
    {
        Yii::$app->user->setIdentity($this->admin());
        $this->post([]);

        $response = Yii::$app->runAction('auth/logout');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame([302, self::HOST . '/login', true], [$response->statusCode, $response->headers->get('Location'), Yii::$app->user->getIsGuest()]);
    }

    private function signInWith(RateLimiterInterface $limiter): void
    {
        Yii::$container->set(UserRepositoryInterface::class, self::createStub(UserRepositoryInterface::class));
        Yii::$container->set(RateLimiterInterface::class, $limiter);

        Yii::$app->user->setIdentity(null);
        $this->post(['username' => 'anna', 'password' => self::PASSWORD]);
    }

    private function signInWillSucceed(): void
    {
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findByUsername')->willReturn($this->admin());
        Yii::$container->set(UserRepositoryInterface::class, $users);
        Yii::$container->set(RateLimiterInterface::class, $this->limiterWithinLimit());
    }

    private function limiterWithinLimit(): RateLimiterInterface
    {
        $limiter = self::createStub(RateLimiterInterface::class);
        $limiter->method('hit')->willReturn(true);

        return $limiter;
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(array $fields): void
    {
        Yii::$app->request->headers->set('X-Http-Method-Override', 'POST');
        Yii::$app->request->setBodyParams(['LoginForm' => $fields]);
    }

    private function admin(): User
    {
        $admin = new User();
        $admin->id = 7;
        $admin->username = 'anna';
        $admin->password_hash = Yii::$app->getSecurity()->generatePasswordHash(self::PASSWORD);
        $admin->auth_key = 'auth-key-of-anna';
        $admin->status = User::STATUS_ACTIVE;

        return $admin;
    }
}
