<?php

declare(strict_types=1);

namespace common\tests\unit\web;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\web\ErrorPage;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Throwable;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\ServerErrorHttpException;
use yii\web\TooManyRequestsHttpException;
use yii\web\UnauthorizedHttpException;
use yii\web\UnprocessableEntityHttpException;

final class ErrorPageTest extends Unit
{
    #[TestDox('UNIT-ERR-01: код ответа исключения и заголовок error.<код>.title')]
    #[DataProvider('knownCodes')]
    public function testTitleComesFromStatusCode(HttpException $exception, int $code): void
    {
        $page = ErrorPage::of($exception);

        self::assertSame([$code, Yii::t('app', sprintf('error.%d.title', $code))], [$page->statusCode, $page->title]);
    }

    /**
     * @return array<string, array{HttpException, int}>
     */
    public static function knownCodes(): array
    {
        return [
            'код 400' => [new BadRequestHttpException(), 400],
            'код 403' => [new ForbiddenHttpException(), 403],
            'код 404' => [new NotFoundHttpException(), 404],
            'код 405' => [new MethodNotAllowedHttpException(), 405],
            'код 409' => [new ConflictHttpException(), 409],
            'код 429' => [new TooManyRequestsHttpException(), 429],
            'код 500' => [new ServerErrorHttpException(), 500],
        ];
    }

    #[TestDox('UNIT-ERR-02: у 409 текст — сообщение исключения')]
    public function testConflictShowsExceptionMessage(): void
    {
        $page = ErrorPage::of(new ConflictHttpException(Yii::t('app', 'admin.edit_deleted')));

        self::assertSame(Yii::t('app', 'admin.edit_deleted'), $page->text);
    }

    #[TestDox('UNIT-ERR-02: у 409 без сообщения и у остальных кодов — общий текст кода, текст фреймворка не показывается')]
    #[DataProvider('generalTexts')]
    public function testOtherCodesShowGeneralText(HttpException $exception, string $textKey): void
    {
        $page = ErrorPage::of($exception);

        self::assertSame(Yii::t('app', $textKey), $page->text);
    }

    /**
     * @return array<string, array{HttpException, string}>
     */
    public static function generalTexts(): array
    {
        return [
            '409 без сообщения' => [new ConflictHttpException(), 'error.409.text'],
            '400 с текстом CSRF фреймворка' => [new BadRequestHttpException('Unable to verify your data submission.'), 'error.400.text'],
            '404 с текстом маршрута' => [new NotFoundHttpException('Page not found.'), 'error.404.text'],
            '405 с текстом VerbFilter' => [new MethodNotAllowedHttpException('Method Not Allowed. This URL can only handle: POST.'), 'error.405.text'],
        ];
    }

    #[TestDox('UNIT-ERR-03: код вне таблицы получает тексты своего класса, не HttpException — 500 без своего сообщения')]
    #[DataProvider('otherExceptions')]
    public function testOtherCodesFallBackToTheirClass(Throwable $exception, int $code, int $textCode): void
    {
        $page = ErrorPage::of($exception);

        self::assertSame(
            [$code, Yii::t('app', sprintf('error.%d.title', $textCode)), Yii::t('app', sprintf('error.%d.text', $textCode))],
            [$page->statusCode, $page->title, $page->text],
        );
    }

    /**
     * @return array<string, array{Throwable, int, int}>
     */
    public static function otherExceptions(): array
    {
        return [
            '401 → тексты 400' => [new UnauthorizedHttpException(), 401, 400],
            '422 → тексты 400' => [new UnprocessableEntityHttpException(), 422, 400],
            '503 → тексты 500' => [new HttpException(503, 'Redis down'), 503, 500],
            'не HttpException → 500' => [new RuntimeException('SQLSTATE[08006] connection refused'), 500, 500],
        ];
    }
}
