<?php

declare(strict_types=1);

namespace frontend\tests\regression;

use Codeception\Attribute\DataProvider;
use Codeception\Attribute\Group;
use Codeception\Example;
use common\tests\_support\ErrorPageView;
use common\tests\_support\Examples;
use frontend\tests\RegressionTester;
use Yii;

/** Страницы ошибок публичной части (docs/test-plans/regression.md, «Страницы ошибок»). */
#[Group('parallel')]
final class ErrorPageCest
{
    /**
     * REG-G-24: ошибка публичной части — свой вид: русский заголовок кода, общий текст, ссылка «На главную»
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('errors')]
    public function errorShowsOwnPage(RegressionTester $I, Example $example): void
    {
        $code = Examples::int($example, 'code');

        $I->send(Examples::string($example, 'method'), Examples::string($example, 'url'), Examples::array($example, 'params'));

        $I->seeResponseCodeIs($code);
        $I->assertSame([
            'title' => Yii::t('app', sprintf('error.%d.title', $code)),
            'text' => Yii::t('app', sprintf('error.%d.text', $code)),
            'links' => [Yii::t('app', 'error.back.home') => '/'],
        ], ErrorPageView::of($I->grabResponse()));
    }

    /**
     * Ошибки публичной части. POST отправки без CSRF-токена отклоняется до лимита отправок: счётчик не тратится.
     *
     * @return array<string, array{method: string, url: string, params: array<string, string>, code: int}>
     */
    private function errors(): array
    {
        return [
            'неизвестный адрес → 404' => ['method' => 'GET', 'url' => '/no-such-page', 'params' => [], 'code' => 404],
            'GET на адрес отправки → 405' => ['method' => 'GET', 'url' => '/comments', 'params' => [], 'code' => 405],
            'POST отправки без CSRF → 400' => ['method' => 'POST', 'url' => '/comments', 'params' => ['name' => 'Ян', 'text' => 'Текст'], 'code' => 400],
        ];
    }
}
