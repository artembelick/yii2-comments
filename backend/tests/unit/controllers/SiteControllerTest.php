<?php

declare(strict_types=1);

namespace backend\tests\unit\controllers;

use Codeception\Test\Unit;
use common\tests\_support\ErrorPageView;
use PHPUnit\Framework\Attributes\TestDox;
use Yii;
use yii\web\ConflictHttpException;
use yii\web\NotFoundHttpException;

final class SiteControllerTest extends Unit
{
    #[TestDox('UNIT-ERR-04: site/error админки открыт гостю — код исключения, заголовок и текст ErrorPage, ссылка «К списку»')]
    public function testErrorPageLinksToList(): void
    {
        Yii::$app->user->setIdentity(null);
        Yii::$app->errorHandler->exception = new NotFoundHttpException();

        $html = Yii::$app->runAction('site/error');

        self::assertIsString($html);
        self::assertSame(404, Yii::$app->response->statusCode);
        self::assertSame([
            'title' => Yii::t('app', 'error.404.title'),
            'text' => Yii::t('app', 'error.404.text'),
            'links' => [Yii::t('app', 'error.back.list') => '/'],
        ], ErrorPageView::of($html));
    }

    #[TestDox('UNIT-ERR-04: site/error админки на 409 — текст исключения и ссылка на «Удалённые» вместо «К списку»')]
    public function testConflictLinksToDeletedTab(): void
    {
        Yii::$app->errorHandler->exception = new ConflictHttpException(Yii::t('app', 'admin.edit_deleted'));

        $html = Yii::$app->runAction('site/error');

        self::assertIsString($html);
        self::assertSame(409, Yii::$app->response->statusCode);
        self::assertSame([
            'title' => Yii::t('app', 'error.409.title'),
            'text' => Yii::t('app', 'admin.edit_deleted'),
            'links' => [Yii::t('app', 'error.back.deleted') => '/?status=deleted'],
        ], ErrorPageView::of($html));
    }
}
