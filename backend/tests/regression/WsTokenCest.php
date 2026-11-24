<?php

declare(strict_types=1);

namespace backend\tests\regression;

use backend\tests\RegressionTester;
use Codeception\Attribute\Group;

#[Group('parallel')]
final class WsTokenCest
{
    /** REG-A-60: POST токена WS дважды → 200, JSON с полем token, токены разные */
    public function issuesNewTokenEachTime(RegressionTester $I): void
    {
        $I->amSignedInAs($I->haveAdmin());
        $I->haveHttpHeader('X-CSRF-Token', $I->grabCsrfToken());

        $first = $I->issueWsToken();
        $second = $I->issueWsToken();

        $I->assertMatchesRegularExpression('~^[A-Za-z0-9_-]{43}$~D', $first);
        $I->assertMatchesRegularExpression('~^[A-Za-z0-9_-]{43}$~D', $second);
        $I->assertNotSame($first, $second);
    }

    /** REG-A-60b: POST токена без CSRF-заголовка → 400, токен не выдан */
    public function tokenWithoutCsrfIsNotIssued(RegressionTester $I): void
    {
        $I->amSignedInAs($I->haveAdmin());

        $I->sendPost('/api/ws-token');

        $I->seeResponseCodeIs(400);
        $I->dontSeeResponseContains('"token"');
    }

    /** REG-A-60b: ответ с токеном не кэшируется: Cache-Control: no-store */
    public function issuedTokenIsNotCached(RegressionTester $I): void
    {
        $I->amSignedInAs($I->haveAdmin());
        $I->haveHttpHeader('X-CSRF-Token', $I->grabCsrfToken());

        $I->issueWsToken();

        $I->assertContains('no-store', $I->grabCacheControlDirectives());
    }

    /** REG-A-60a: GET токена → 405 */
    public function rejectsGet(RegressionTester $I): void
    {
        $I->amSignedInAs($I->haveAdmin());

        $I->sendGet('/api/ws-token');

        $I->seeResponseCodeIs(405);
    }
}
