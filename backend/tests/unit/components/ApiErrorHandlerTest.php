<?php

declare(strict_types=1);

namespace backend\tests\unit\components;

use backend\components\ApiErrorHandler;
use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\tests\_support\ErrorPageView;
use Override;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Throwable;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;
use yii\web\UnauthorizedHttpException;
use yii\web\UnprocessableEntityHttpException;

final class ApiErrorHandlerTest extends Unit
{
    /**
     * @param array{error: string} $expected
     */
    #[DataProvider('exceptions')]
    #[TestDox('UNIT-AEH-01: исключение → тело с одним полем error = код ошибки для /api/*, без сообщения исключения')]
    public function testConvertsExceptionToErrorCode(Throwable $exception, array $expected): void
    {
        $response = $this->silentResponse();
        Yii::$app->request->setUrl('/api/comments');

        $this->handler()->handleException($exception);

        self::assertSame($expected, $response->data);
    }

    #[TestDox('UNIT-AEH-01: 400 фреймворка на неверный CSRF-токен → error = "csrf"')]
    public function testCsrfFailureHasOwnCode(): void
    {
        $response = $this->silentResponse();
        Yii::$app->request->setUrl('/api/ws-token');

        $this->handler()->handleException(new BadRequestHttpException(Yii::t('yii', 'Unable to verify your data submission.')));

        self::assertSame(['error' => 'csrf'], $response->data);
    }

    /**
     * Исключения, которые бросает фреймворк или код админки, и код ошибки для клиента.
     *
     * @return array<string, array{Throwable, array{error: string}}>
     */
    public static function exceptions(): array
    {
        return [
            'код 400 прочий' => [new BadRequestHttpException('Invalid data received for parameter "status".'), ['error' => 'bad_request']],
            'код 401' => [new UnauthorizedHttpException(), ['error' => 'unauthorized']],
            'код 403' => [new ForbiddenHttpException(), ['error' => 'forbidden']],
            'код 404' => [new NotFoundHttpException('Page not found.'), ['error' => 'not_found']],
            'код 405' => [new MethodNotAllowedHttpException('Method Not Allowed.'), ['error' => 'method_not_allowed']],
            'код 503' => [new HttpException(503), ['error' => 'unavailable']],
            'код 500' => [new ServerErrorHttpException(), ['error' => 'internal_error']],
            '422 вне таблицы → 4xx' => [new UnprocessableEntityHttpException(), ['error' => 'bad_request']],
            '502 вне таблицы → 5xx' => [new HttpException(502), ['error' => 'internal_error']],
            'не HttpException' => [new RuntimeException('SQLSTATE[08006] пароль БД'), ['error' => 'internal_error']],
        ];
    }

    #[TestDox('UNIT-AEH-02: ошибка на адресе /api/… при формате HTML → JSON с полем error, код ответа исключения')]
    public function testApiErrorIsJsonWhateverTheFormat(): void
    {
        $response = $this->silentResponse();
        Yii::$app->request->setUrl('/api/unknown');

        $this->handler()->handleException(new NotFoundHttpException());

        self::assertSame(
            [Response::FORMAT_JSON, 404, ['error' => 'not_found']],
            [$response->format, $response->statusCode, $response->data],
        );
    }

    #[TestDox('UNIT-AEH-02: ошибка на HTML-адресе → вид site/error, формат HTML')]
    public function testHtmlErrorKeepsErrorPage(): void
    {
        $response = $this->silentResponse();
        Yii::$app->request->setUrl('/comments/1/edit');

        $this->handler()->handleException(new NotFoundHttpException());

        self::assertIsString($response->data);
        self::assertSame(
            [Response::FORMAT_HTML, 404, Yii::t('app', 'error.404.title')],
            [$response->format, $response->statusCode, ErrorPageView::of($response->data)['title']],
        );
    }

    /** Обработчик как в конфиге админки, но без сброса буферов вывода и без exit: их держит PHPUnit. */
    private function handler(): ApiErrorHandler
    {
        return new ApiErrorHandler(['errorAction' => 'site/error', 'discardExistingOutput' => false, 'silentExitOnException' => true]);
    }

    /** Ответ приложения, который не печатает себя: обработчик ошибок вызывает send(), а вывод в тесте запрещён. */
    private function silentResponse(): Response
    {
        $response = new class extends Response {
            #[Override]
            public function send(): void {}
        };
        Yii::$app->set('response', $response);

        return $response;
    }
}
