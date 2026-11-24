<?php

declare(strict_types=1);

namespace backend\tests\regression;

use backend\tests\RegressionTester;
use Codeception\Attribute\DataProvider;
use Codeception\Attribute\Group;
use Codeception\Example;
use common\models\User;
use common\tests\_support\Examples;
use common\tests\_support\FixtureFactory;
use Yii;

#[Group('parallel')]
final class LoginCest
{
    private const string LIST_URL = 'http://admin.comments.localhost/';

    private const string LOGIN_URL = 'http://admin.comments.localhost/login';

    private const string NEW_PASSWORD = 'new-password-for-a09c';

    /** REG-A-01: GET /login → 200 и форма входа */
    public function loginPageShowsForm(RegressionTester $I): void
    {
        $I->amOnPage('/login');

        $I->seeResponseCodeIs(200);
        $I->see(Yii::t('app', 'login.title'), 'h1');
        $I->seeElement('#login-form input[name="LoginForm[username]"]');
        $I->seeElement('#login-form input[name="LoginForm[password]"]');
    }

    /** REG-A-02: верные логин и пароль → 302 на список, cookie сессии HttpOnly и SameSite=Lax */
    public function validCredentialsSignIn(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $I->stopFollowingRedirects();

        $I->submitLogin($admin->username, FixtureFactory::ADMIN_PASSWORD);

        $I->seeResponseCodeIs(302);
        $I->seeHttpHeader('Location', self::LIST_URL);
        $I->assertMatchesRegularExpression(
            '/^comments-admin=(?=.*;\s*httponly)(?=.*;\s*samesite=lax)/mi',
            implode("\n", $I->grabSetCookieHeaders()),
        );
    }

    /** REG-A-03: неверный пароль → 422, общее сообщение, сессии нет */
    public function wrongPasswordIsRejected(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();

        $I->submitLogin($admin->username, 'wrong-password');

        $I->seeResponseCodeIs(422);
        $I->see(Yii::t('app', 'login.invalid'));
        $I->amOnPage('/');
        $I->seeCurrentUrlEquals('/login');
    }

    /** REG-A-04: несуществующий логин → то же сообщение, что в REG-A-03 */
    public function unknownUsernameGetsSameMessage(RegressionTester $I): void
    {
        $ghost = 'ghost-' . $I->fixtureFactory()->tag();

        $I->submitLogin($ghost, FixtureFactory::ADMIN_PASSWORD);

        $I->seeResponseCodeIs(422);
        $I->see(Yii::t('app', 'login.invalid'));
    }

    /**
     * REG-A-05: пустой логин или пароль → ошибка поля
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('emptyFields')]
    public function emptyFieldIsReported(RegressionTester $I, Example $example): void
    {
        $I->submitLogin(Examples::string($example, 'username'), Examples::string($example, 'password'));

        $I->seeResponseCodeIs(422);
        $I->see(Yii::t('app', Examples::string($example, 'message')));
    }

    /**
     * @return array<string, array{username: string, password: string, message: string}>
     */
    private function emptyFields(): array
    {
        return [
            'пустой логин' => ['username' => '', 'password' => 'some-password', 'message' => 'login.username_required'],
            'пустой пароль' => ['username' => 'anna', 'password' => '', 'message' => 'login.password_required'],
        ];
    }

    /** REG-A-06: логин в верхнем регистре → вход успешен */
    public function usernameInUpperCaseSignsIn(RegressionTester $I): void
    {
        $admin = $I->haveAdmin(['username' => 'a_login-' . $I->fixtureFactory()->tag()]);
        $I->stopFollowingRedirects();

        $I->submitLogin(strtoupper($admin->username), FixtureFactory::ADMIN_PASSWORD);

        $I->seeResponseCodeIs(302);
        $I->seeHttpHeader('Location', self::LIST_URL);
    }

    /** REG-A-06: логин в смешанном регистре → вход успешен */
    public function usernameInMixedCaseSignsIn(RegressionTester $I): void
    {
        $admin = $I->haveAdmin(['username' => 'a_login-' . $I->fixtureFactory()->tag()]);
        $I->stopFollowingRedirects();

        $I->submitLogin(ucfirst($admin->username), FixtureFactory::ADMIN_PASSWORD);

        $I->seeResponseCodeIs(302);
        $I->seeHttpHeader('Location', self::LIST_URL);
    }

    /** REG-A-07: вход без CSRF → 400 */
    public function loginWithoutCsrfIsRejected(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();

        $I->sendPost('/login', ['LoginForm' => ['username' => $admin->username, 'password' => FixtureFactory::ADMIN_PASSWORD]]);

        $I->seeResponseCodeIs(400);
    }

    /** REG-A-08: 5 неудач подряд, затем верный пароль → 429 */
    public function sixthAttemptIsLimited(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $I->failLogin($admin->username, 5);

        $I->submitLogin($admin->username, FixtureFactory::ADMIN_PASSWORD);

        $I->seeResponseCodeIs(429);
        $I->see(Yii::t('app', 'login.too_many_attempts'));
    }

    /** REG-A-08a: успешный вход после 4 неудач обнуляет счётчик: ещё 4 неудачи не блокируют */
    public function successResetsAttempts(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $I->failLogin($admin->username, 4);
        $I->amSignedInAs($admin);
        $I->click(Yii::t('app', 'admin.logout'));
        $I->failLogin($admin->username, 4);
        $I->stopFollowingRedirects();

        $I->submitLogin($admin->username, FixtureFactory::ADMIN_PASSWORD);

        $I->seeResponseCodeIs(302);
    }

    /** REG-A-08b: 5 неудач для Админа 1 не мешают войти Админу 2 с того же IP */
    public function limitIsPerUsername(RegressionTester $I): void
    {
        $admin1 = $I->haveAdmin();
        $admin2 = $I->haveAdmin();
        $I->failLogin($admin1->username, 5);
        $I->stopFollowingRedirects();

        $I->submitLogin($admin2->username, FixtureFactory::ADMIN_PASSWORD);

        $I->seeResponseCodeIs(302);
    }

    /** REG-A-09: заблокированный админ → то же сообщение, что в REG-A-03 */
    public function blockedAdminGetsSameMessage(RegressionTester $I): void
    {
        $blocked = $I->haveAdmin(['status' => User::STATUS_BLOCKED]);

        $I->submitLogin($blocked->username, FixtureFactory::ADMIN_PASSWORD);

        $I->seeResponseCodeIs(422);
        $I->see(Yii::t('app', 'login.invalid'));
    }

    /** REG-A-09b: вошедшего Админа 1 блокируют консольной командой → его следующий запрос уходит на логин */
    public function blockedAdminLosesSession(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $I->amSignedInAs($admin);
        $I->runAdminCommand('admin/block', $admin->username);
        $I->stopFollowingRedirects();

        $I->amOnPage('/');

        $I->seeResponseCodeIs(302);
        $I->seeHttpHeader('Location', self::LOGIN_URL);
    }

    /** REG-A-09c: Админ 1 вошёл, admin/password меняет ему пароль → старая сессия уходит на логин */
    public function passwordChangeEndsOldSession(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $I->amSignedInAs($admin);
        $I->runAdminCommand('admin/password', $admin->username, self::NEW_PASSWORD . "\n");
        $I->stopFollowingRedirects();

        $I->amOnPage('/');

        $I->seeResponseCodeIs(302);
        $I->seeHttpHeader('Location', self::LOGIN_URL);
    }

    /** REG-A-09c: после admin/password вход с новым паролем работает */
    public function newPasswordSignsIn(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $I->runAdminCommand('admin/password', $admin->username, self::NEW_PASSWORD . "\n");
        $I->stopFollowingRedirects();

        $I->submitLogin($admin->username, self::NEW_PASSWORD);

        $I->seeResponseCodeIs(302);
        $I->seeHttpHeader('Location', self::LIST_URL);
    }

    /** REG-A-10: вошедший админ открывает /login → редирект на список */
    public function signedInAdminSkipsLoginPage(RegressionTester $I): void
    {
        $I->amSignedInAs($I->haveAdmin());
        $I->stopFollowingRedirects();

        $I->amOnPage('/login');

        $I->seeResponseCodeIs(302);
        $I->seeHttpHeader('Location', self::LIST_URL);
    }

    /** REG-A-11: id сессии после входа другой */
    public function loginRegeneratesSessionId(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $I->amOnPage('/');
        $before = $I->grabSessionId();

        $I->amSignedInAs($admin);

        $I->assertNotNull($before);
        $I->assertNotSame($before, $I->grabSessionId());
    }

    /** REG-A-12: logout через GET → 405 */
    public function logoutRejectsGet(RegressionTester $I): void
    {
        $I->amSignedInAs($I->haveAdmin());

        $I->amOnPage('/logout');

        $I->seeResponseCodeIs(405);
    }

    /** REG-A-12a: logout через POST без CSRF → 400 */
    public function logoutWithoutCsrfIsRejected(RegressionTester $I): void
    {
        $I->amSignedInAs($I->haveAdmin());

        $I->sendPost('/logout');

        $I->seeResponseCodeIs(400);
    }

    /** REG-A-12b: после logout старая cookie сессии не пускает в админку */
    public function logoutEndsSession(RegressionTester $I): void
    {
        $I->amSignedInAs($I->haveAdmin());
        $oldSession = (string) $I->grabSessionId();
        $I->sendPost('/logout', ['_csrf-backend' => $I->grabCsrfToken()]);
        $I->seeResponseCodeIs(200);
        $I->setCookie('comments-admin', $oldSession);
        $I->stopFollowingRedirects();

        $I->amOnPage('/');

        $I->seeResponseCodeIs(302);
        $I->seeHttpHeader('Location', self::LOGIN_URL);
    }

    /** REG-A-13: без входа открыть страницу правки, войти → открыта она же */
    public function loginReturnsToRequestedPage(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $comment = $I->haveComment();
        $editUrl = sprintf('/comments/%d/edit', $comment->id);
        $I->amOnPage($editUrl);
        $I->stopFollowingRedirects();

        $I->submitForm('#login-form', ['LoginForm[username]' => $admin->username, 'LoginForm[password]' => FixtureFactory::ADMIN_PASSWORD]);

        $I->seeResponseCodeIs(302);
        $I->seeHttpHeader('Location', 'http://admin.comments.localhost' . $editUrl);
    }

    /**
     * REG-A-13a: попытка подменить returnUrl чужим адресом → после входа список
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('hostileReturnUrls')]
    public function loginNeverRedirectsOutside(RegressionTester $I, Example $example): void
    {
        $admin = $I->haveAdmin();
        $I->haveHttpHeader(Examples::string($example, 'header'), Examples::string($example, 'value'));
        $I->amOnPage(Examples::string($example, 'page'));
        $I->unsetHttpHeader(Examples::string($example, 'header'));
        $I->stopFollowingRedirects();

        $I->submitLogin($admin->username, FixtureFactory::ADMIN_PASSWORD);

        $I->seeResponseCodeIs(302);
        $I->seeHttpHeader('Location', self::LIST_URL);
    }

    /**
     * @return array<string, array{page: string, header: string, value: string}>
     */
    private function hostileReturnUrls(): array
    {
        return [
            'внешний хост в X-Forwarded-Host' => ['page' => '/', 'header' => 'X-Forwarded-Host', 'value' => 'evil.example'],
            '//evil.example в пути' => ['page' => '//evil.example', 'header' => 'X-Test', 'value' => '1'],
            'javascript: в X-Rewrite-Url' => ['page' => '/', 'header' => 'X-Rewrite-Url', 'value' => 'javascript:alert(1)'],
        ];
    }
}
