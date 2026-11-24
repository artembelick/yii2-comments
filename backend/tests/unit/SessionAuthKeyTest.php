<?php

declare(strict_types=1);

namespace backend\tests\unit;

use backend\components\AdminAccessControl;
use Codeception\Test\Unit;
use common\models\User;
use common\repositories\UserRepositoryInterface;
use Override;
use PHPUnit\Framework\Attributes\TestDox;
use Yii;
use yii\web\Controller;

/**
 * Сессия админа после смены пароля или блокировки: на следующем запросе yii\web\User восстанавливает вход из сессии,
 * сверяет `__authKey` сессии с текущим `auth_key` админа и ищет админа только среди активных.
 * Не совпало или не нашлось — запрос гостевой, и доступ решает AdminAccessControl.
 */
final class SessionAuthKeyTest extends Unit
{
    private const int ADMIN_ID = 7;

    private const string AUTH_KEY = 'auth-key-at-login-0123456789abcd';

    #[TestDox('UNIT-GUARD-01: auth_key в сессии совпадает с текущим auth_key админа → запрос продолжается')]
    public function testMatchingAuthKeyKeepsAdminSignedIn(): void
    {
        $this->adminInRepository(self::AUTH_KEY);
        $this->sessionOfAdmin(self::AUTH_KEY);

        $result = $this->protectedController()->runAction('index');

        self::assertSame(['закрытая страница', 200], [$result, Yii::$app->response->statusCode]);
    }

    #[TestDox('UNIT-GUARD-02: auth_key сменился (пароль сменили) → HTML-маршрут уходит на логин')]
    public function testChangedAuthKeyRedirectsToLogin(): void
    {
        $this->adminInRepository('auth-key-after-password-change-0');
        $this->sessionOfAdmin(self::AUTH_KEY);
        Yii::$app->request->setUrl('/');

        $result = $this->protectedController()->runAction('index');

        self::assertSame(
            [null, 302, 'http://admin.comments.localhost/login', true],
            [$result, Yii::$app->response->statusCode, Yii::$app->response->headers->get('Location'), Yii::$app->user->getIsGuest()],
        );
    }

    #[TestDox('UNIT-GUARD-02: auth_key сменился → JSON-маршрут /api/* отвечает 401')]
    public function testChangedAuthKeyOnApiIsUnauthorized(): void
    {
        $this->adminInRepository('auth-key-after-password-change-0');
        $this->sessionOfAdmin(self::AUTH_KEY);
        Yii::$app->request->setUrl('/api/comments');

        $result = $this->protectedController()->runAction('index');

        self::assertSame([null, 401, ['error' => 'unauthorized']], [$result, Yii::$app->response->statusCode, Yii::$app->response->data]);
    }

    #[TestDox('UNIT-GUARD-03: админа заблокировали после входа → его сессия гостевая, HTML-маршрут уходит на логин')]
    public function testBlockedAdminSessionRedirectsToLogin(): void
    {
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findActiveById')->willReturn(null);
        Yii::$container->set(UserRepositoryInterface::class, $users);
        $this->sessionOfAdmin(self::AUTH_KEY);
        Yii::$app->request->setUrl('/');

        $result = $this->protectedController()->runAction('index');

        self::assertSame(
            [null, 302, 'http://admin.comments.localhost/login'],
            [$result, Yii::$app->response->statusCode, Yii::$app->response->headers->get('Location')],
        );
    }

    /** Активный админ с текущим auth_key $authKey в репозитории. */
    private function adminInRepository(string $authKey): void
    {
        $admin = new User();
        $admin->id = self::ADMIN_ID;
        $admin->auth_key = $authKey;
        $admin->status = User::STATUS_ACTIVE;

        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findActiveById')->willReturn($admin);
        Yii::$container->set(UserRepositoryInterface::class, $users);
    }

    /** Сессия, в которой админ вошёл раньше: id и auth_key на момент входа, как их записал yii\web\User::login(). */
    private function sessionOfAdmin(string $authKeyAtLogin): void
    {
        Yii::$app->session->set(Yii::$app->user->idParam, self::ADMIN_ID);
        Yii::$app->session->set(Yii::$app->user->authKeyParam, $authKeyAtLogin);
    }

    private function protectedController(): Controller
    {
        return new class ('probe', Yii::$app) extends Controller {
            #[Override]
            public function behaviors(): array
            {
                return ['access' => AdminAccessControl::class];
            }

            public function actionIndex(): string
            {
                return 'закрытая страница';
            }
        };
    }
}
