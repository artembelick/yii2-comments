<?php

declare(strict_types=1);

namespace backend\tests\regression;

use backend\tests\RegressionTester;
use Codeception\Attribute\DataProvider;
use Codeception\Attribute\Group;
use Codeception\Example;
use common\tests\_support\Examples;

#[Group('parallel')]
final class AccessCest
{
    /**
     * REG-A-20: без входа любой маршрут админки → 302 на логин
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('adminPages')]
    public function guestIsRedirectedToLogin(RegressionTester $I, Example $example): void
    {
        $I->stopFollowingRedirects();

        $I->amOnPage(Examples::string($example, 'url'));

        $I->seeResponseCodeIs(302);
        $I->seeHttpHeader('Location', 'http://admin.comments.localhost/login');
    }

    /**
     * Маршруты админки, которые есть на этом срезе. Правка, удаление и восстановление добавляются со своими срезами.
     *
     * @return array<string, array{url: string}>
     */
    private function adminPages(): array
    {
        return [
            'список' => ['url' => '/'],
            '«Удалённые»' => ['url' => '/?status=deleted'],
        ];
    }

    /**
     * REG-A-21: без входа JSON-эндпоинт → 401, а не редирект
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('jsonEndpoints')]
    public function guestGetsUnauthorizedOnJson(RegressionTester $I, Example $example): void
    {
        $I->stopFollowingRedirects();

        $I->send(Examples::string($example, 'method'), Examples::string($example, 'url'));

        $I->seeResponseCodeIs(401);
        $I->seeResponseContainsJson(['error' => 'unauthorized']);
        $I->dontSeeHttpHeader('Location');
    }

    /**
     * JSON-эндпоинты админки, которые есть на этом срезе. JSON-список добавляет срез 05.
     *
     * @return array<string, array{method: string, url: string}>
     */
    private function jsonEndpoints(): array
    {
        return [
            'токен WS' => ['method' => 'POST', 'url' => '/api/ws-token'],
        ];
    }
}
