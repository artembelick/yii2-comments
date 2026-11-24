<?php

declare(strict_types=1);

namespace backend\tests\regression;

use backend\tests\RegressionTester;
use Codeception\Attribute\DataProvider;
use Codeception\Attribute\Group;
use Codeception\Example;
use common\tests\_support\Examples;

/**
 * JSON-эндпоинты админки отвечают JSON всегда, в том числе ошибками (docs/test-plans/regression.md, «JSON»):
 * клиент админки разбирает ответ как JSON, а HTML-вид ошибки предназначен людям.
 */
#[Group('parallel')]
final class JsonContractCest
{
    private const string JSON = 'application/json; charset=UTF-8';

    /**
     * REG-A-61b: успешный ответ JSON-эндпоинта — Content-Type application/json
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('endpoints')]
    public function successIsJson(RegressionTester $I, Example $example): void
    {
        $I->amSignedInAs($I->haveAdmin());
        $I->haveHttpHeader('X-CSRF-Token', $I->grabCsrfToken());

        $I->send(Examples::string($example, 'method'), Examples::string($example, 'url'));

        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', self::JSON);
    }

    /**
     * REG-A-61b: 401 гостю — JSON, ровно поле error = unauthorized
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('endpoints')]
    public function unauthorizedIsJson(RegressionTester $I, Example $example): void
    {
        $I->stopFollowingRedirects();

        $I->send(Examples::string($example, 'method'), Examples::string($example, 'url'));

        $I->seeResponseCodeIs(401);
        $I->seeHttpHeader('Content-Type', self::JSON);
        $I->assertSame('{"error":"unauthorized"}', $I->grabResponse());
    }

    /**
     * Оба JSON-эндпоинта админки и их метод.
     *
     * @return array<string, array{method: string, url: string}>
     */
    private function endpoints(): array
    {
        return [
            'список' => ['method' => 'GET', 'url' => '/api/comments'],
            'токен WS' => ['method' => 'POST', 'url' => '/api/ws-token'],
        ];
    }

    /** REG-A-61b: 400 на неверный status — JSON, ровно поле error = invalid_status */
    public function invalidStatusIsJson(RegressionTester $I): void
    {
        $I->amSignedInAs($I->haveAdmin());

        $I->sendGet('/api/comments', ['status' => 'foo']);

        $I->seeResponseCodeIs(400);
        $I->seeHttpHeader('Content-Type', self::JSON);
        $I->assertSame('{"error":"invalid_status"}', $I->grabResponse());
    }

    /**
     * REG-A-61b: ошибка фреймворка (CSRF, метод, маршрут) на адресе /api/* — JSON той же формы, что свои ошибки
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('frameworkErrors')]
    public function frameworkErrorIsJson(RegressionTester $I, Example $example): void
    {
        $I->amSignedInAs($I->haveAdmin());

        $I->send(Examples::string($example, 'method'), Examples::string($example, 'url'));

        $I->seeResponseCodeIs(Examples::int($example, 'code'));
        $I->seeHttpHeader('Content-Type', self::JSON);
        $I->assertSame(Examples::string($example, 'body'), $I->grabResponse());
    }

    /**
     * Запросы вошедшего админа без CSRF-заголовка, которые отклоняет сам фреймворк. Метод проверяется раньше CSRF.
     *
     * @return array<string, array{method: string, url: string, code: int, body: string}>
     */
    private function frameworkErrors(): array
    {
        return [
            'токен без CSRF → 400' => ['method' => 'POST', 'url' => '/api/ws-token', 'code' => 400, 'body' => '{"error":"csrf"}'],
            'GET токена → 405' => ['method' => 'GET', 'url' => '/api/ws-token', 'code' => 405, 'body' => '{"error":"method_not_allowed"}'],
            'POST списка → 405' => ['method' => 'POST', 'url' => '/api/comments', 'code' => 405, 'body' => '{"error":"method_not_allowed"}'],
            'неизвестный адрес → 404' => ['method' => 'GET', 'url' => '/api/unknown', 'code' => 404, 'body' => '{"error":"not_found"}'],
        ];
    }

    /** REG-A-61c: POST /api/comments с CSRF → 405: список только читается */
    public function listIsReadOnly(RegressionTester $I): void
    {
        $I->amSignedInAs($I->haveAdmin());
        $I->haveHttpHeader('X-CSRF-Token', $I->grabCsrfToken());

        $I->sendPost('/api/comments');

        $I->seeResponseCodeIs(405);
    }
}
