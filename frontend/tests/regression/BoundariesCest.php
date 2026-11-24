<?php

declare(strict_types=1);

namespace frontend\tests\regression;

use Codeception\Attribute\DataProvider;
use Codeception\Attribute\Group;
use Codeception\Example;
use common\tests\_support\Examples;
use common\web\SessionCookie;
use frontend\tests\RegressionTester;

#[Group('parallel')]
final class BoundariesCest
{
    /**
     * REG-G-30: адреса правки, удаления и восстановления на публичной части → 404
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('adminActions')]
    public function adminActionsAreNotOnPublicSite(RegressionTester $I, Example $example): void
    {
        $comment = $I->haveComment();
        $I->amOnPage('/');
        $token = $I->grabCsrfToken();
        $url = sprintf(Examples::string($example, 'url'), $comment->id);

        $I->send(Examples::string($example, 'method'), $url, [RegressionTester::CSRF_PARAM => $token]);

        $I->seeResponseCodeIs(404);
    }

    /**
     * @return array<string, array{method: string, url: string}>
     */
    private function adminActions(): array
    {
        return [
            'правка, GET' => ['method' => 'GET', 'url' => '/comments/%d/edit'],
            'правка, POST' => ['method' => 'POST', 'url' => '/comments/%d/edit'],
            'удаление, POST' => ['method' => 'POST', 'url' => '/comments/%d/delete'],
            'восстановление, POST' => ['method' => 'POST', 'url' => '/comments/%d/restore'],
        ];
    }

    /** REG-G-31: cookie публичной части в запросе к админке → редирект на логин */
    public function publicCookiesDoNotOpenAdmin(RegressionTester $I): void
    {
        $I->amOnPage('/');
        $session = $I->grabCookieValue(SessionCookie::PUBLIC_SITE);
        $I->setCookie(SessionCookie::PUBLIC_SITE, (string) $session, ['domain' => $I->adminHost()]);
        $I->setCookie(RegressionTester::CSRF_PARAM, (string) $I->grabCookieValue(RegressionTester::CSRF_PARAM), ['domain' => $I->adminHost()]);
        $I->stopFollowingRedirects();

        $I->amOnUrl($I->adminUrl('/'));

        $I->assertNotNull($session, 'у гостя есть сессия публичной части');
        $I->seeResponseCodeIs(302);
        $I->seeHttpHeader('Location', $I->adminUrl('/login'));
    }
}
