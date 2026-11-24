<?php

declare(strict_types=1);

namespace backend\tests\unit\components;

use backend\components\AdminAccessControl;
use Codeception\Test\Unit;
use Override;
use PHPUnit\Framework\Attributes\TestDox;
use Yii;
use yii\web\Controller;
use yii\web\Response;

final class AdminAccessControlTest extends Unit
{
    #[TestDox('UNIT-ACL-01: гость на HTML-маршруте → редирект на логин, адрес запроса запомнен как returnUrl')]
    public function testGuestIsRedirectedToLogin(): void
    {
        $controller = new class ('probe', Yii::$app) extends Controller {
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
        Yii::$app->request->setUrl('/?status=deleted');
        Yii::$app->user->setIdentity(null);

        $result = $controller->runAction('index');

        self::assertSame(
            [null, 302, 'http://admin.comments.localhost/login', 'http://admin.comments.localhost/?status=deleted'],
            [$result, Yii::$app->response->statusCode, Yii::$app->response->headers->get('Location'), Yii::$app->user->getReturnUrl()],
        );
    }

    #[TestDox('UNIT-ACL-02: гость на маршруте /api/* → 401 и JSON с error = "unauthorized", без редиректа')]
    public function testGuestOnApiGetsUnauthorized(): void
    {
        $controller = new class ('probe', Yii::$app) extends Controller {
            #[Override]
            public function behaviors(): array
            {
                return ['access' => AdminAccessControl::class];
            }

            public function actionIndex(): string
            {
                return 'закрытый JSON';
            }
        };
        Yii::$app->request->setUrl('/api/comments');
        Yii::$app->user->setIdentity(null);

        $result = $controller->runAction('index');

        $response = Yii::$app->response;
        self::assertSame(
            [null, 401, Response::FORMAT_JSON, ['error' => 'unauthorized'], null],
            [$result, $response->statusCode, $response->format, $response->data, $response->headers->get('Location')],
        );
    }
}
