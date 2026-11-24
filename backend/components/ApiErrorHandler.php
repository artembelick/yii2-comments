<?php

declare(strict_types=1);

namespace backend\components;

use Override;
use Throwable;
use Yii;
use yii\web\ErrorHandler;
use yii\web\HttpException;
use yii\web\Response;

/**
 * Обработчик ошибок админки. Ошибку на адресе под `/api/` отдаёт JSON единой формы `{"error": "<код>"}`
 * (docs/test-plans/regression.md, REG-A-61b): клиент админки разбирает ответ как JSON, а HTML-вид ошибки
 * предназначен людям. Это касается и ошибок фреймворка (CSRF, метод, неизвестный маршрут), которые случаются раньше
 * действия. Остальные адреса получают HTML-вид `errorAction`.
 */
final class ApiErrorHandler extends ErrorHandler
{
    /** Код ошибки по коду ответа. Остальные коды получают код своего класса: 4xx — как 400, 5xx — как 500. */
    private const array CODES = [
        400 => 'bad_request',
        401 => 'unauthorized',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        500 => 'internal_error',
        503 => 'unavailable',
    ];

    /** Сообщение, с которым yii\web\Controller::beforeAction бросает 400 на неверный CSRF-токен: своего класса у этой ошибки нет. */
    private const string CSRF_MESSAGE = 'Unable to verify your data submission.';

    /**
     * Тело ошибки для JSON-ответа: только код, без сообщения и трассировки при любом `YII_DEBUG`.
     *
     * @param Throwable $exception
     *
     * @return array{error: string}
     */
    #[Override]
    protected function convertExceptionToArray($exception): array
    {
        $statusCode = $exception instanceof HttpException ? $exception->statusCode : 500;
        if ($statusCode === 400 && $exception->getMessage() === Yii::t('yii', self::CSRF_MESSAGE)) {
            return ['error' => 'csrf'];
        }

        return ['error' => self::CODES[$statusCode] ?? self::CODES[$statusCode < 500 ? 400 : 500]];
    }

    /**
     * @param Throwable $exception
     */
    #[Override]
    protected function renderException($exception): void
    {
        if (ApiRoute::isCurrent()) {
            Yii::$app->response->format = Response::FORMAT_JSON;
        }

        parent::renderException($exception);
    }
}
