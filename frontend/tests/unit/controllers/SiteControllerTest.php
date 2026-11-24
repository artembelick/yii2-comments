<?php

declare(strict_types=1);

namespace frontend\tests\unit\controllers;

use Codeception\Test\Unit;
use common\tests\_support\ErrorPageView;
use PHPUnit\Framework\Attributes\TestDox;
use Yii;
use yii\web\NotFoundHttpException;

final class SiteControllerTest extends Unit
{
    #[TestDox('UNIT-ERR-04: site/error публичной части — код исключения, заголовок и текст ErrorPage, ссылка «На главную»')]
    public function testErrorPageLinksHome(): void
    {
        Yii::$app->errorHandler->exception = new NotFoundHttpException('Page not found.');

        $html = Yii::$app->runAction('site/error');

        self::assertIsString($html);
        self::assertSame(404, Yii::$app->response->statusCode);
        self::assertSame([
            'title' => Yii::t('app', 'error.404.title'),
            'text' => Yii::t('app', 'error.404.text'),
            'links' => [Yii::t('app', 'error.back.home') => '/'],
        ], ErrorPageView::of($html));
    }
}
