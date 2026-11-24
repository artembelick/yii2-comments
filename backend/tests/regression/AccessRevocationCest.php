<?php

declare(strict_types=1);

namespace backend\tests\regression;

use backend\tests\RegressionTester;
use Codeception\Attribute\Group;
use common\tests\_support\RedisChannelProbe;

/**
 * Настоящие консольные команды admin/block, admin/unblock и admin/password на стенде и то, что после них видят
 * HTTP-клиент админки и WS-сервер (docs/test-plans/regression.md, A-09b…A-09d). Сообщения ws.control других тестов
 * в канале не мешают: проверяется, что среди них есть access.revoked этого админа. Метка конца, которую
 * RedisChannelProbe публикует в ws.control, WS-серверу непонятна: он пишет предупреждение в лог и ничего не закрывает.
 */
#[Group('parallel')]
final class AccessRevocationCest
{
    private const string LOGIN_URL = 'http://admin.comments.localhost/login';

    private const string NEW_PASSWORD = 'new-password-for-a09c';

    /** REG-A-09b: admin/block публикует в ws.control access.revoked с id админа */
    public function blockRevokesWsAccess(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $control = RedisChannelProbe::subscribe('ws.control');

        $I->runAdminCommand('admin/block', $admin->username);

        $I->assertContains(['type' => 'access.revoked', 'userId' => $admin->id], $control->messagesUntilMarker());
    }

    /** REG-A-09b: после admin/block токен WS старой сессии не выдаётся: POST /api/ws-token → 401 */
    public function blockedAdminGetsNoWsToken(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $I->amSignedInAs($admin);
        $I->haveHttpHeader('X-CSRF-Token', $I->grabCsrfToken());
        $I->runAdminCommand('admin/block', $admin->username);

        $I->sendPost('/api/ws-token');

        $I->seeResponseCodeIs(401);
    }

    /** REG-A-09c: admin/password публикует в ws.control access.revoked с id админа */
    public function passwordChangeRevokesWsAccess(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $control = RedisChannelProbe::subscribe('ws.control');

        $I->runAdminCommand('admin/password', $admin->username, self::NEW_PASSWORD . "\n");

        $I->assertContains(['type' => 'access.revoked', 'userId' => $admin->id], $control->messagesUntilMarker());
    }

    /** REG-A-09c: после admin/password токен WS старой сессии не выдаётся: POST /api/ws-token → 401 */
    public function adminWithChangedPasswordGetsNoWsToken(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $I->amSignedInAs($admin);
        $I->haveHttpHeader('X-CSRF-Token', $I->grabCsrfToken());
        $I->runAdminCommand('admin/password', $admin->username, self::NEW_PASSWORD . "\n");

        $I->sendPost('/api/ws-token');

        $I->seeResponseCodeIs(401);
    }

    /** REG-A-09d: Админ 1 вошёл, его блокируют и сразу разблокируют, в старую сессию между ними никто не заходил → она уходит на логин */
    public function sessionStaysEndedAfterUnblock(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $I->amSignedInAs($admin);
        $I->runAdminCommand('admin/block', $admin->username);
        $I->runAdminCommand('admin/unblock', $admin->username);
        $I->stopFollowingRedirects();

        $I->amOnPage('/');

        $I->seeResponseCodeIs(302);
        $I->seeHttpHeader('Location', self::LOGIN_URL);
    }
}
