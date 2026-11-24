<?php

declare(strict_types=1);

namespace common\web;

use Throwable;
use Yii;
use yii\web\HttpException;

/**
 * Что показать на HTML-странице ошибки обеих частей (docs/test-plans/messages.md, «Страницы ошибок»): код ответа,
 * заголовок и текст по коду. Текст исключения показывается только у 409 — это свой текст из каталога
 * (`admin.edit_deleted`). У остальных кодов текст общий: сообщения фреймворка (CSRF, метод, маршрут) не для людей,
 * а у исключений не из HttpException в сообщении могут быть детали сбоя.
 */
final readonly class ErrorPage
{
    /** Коды со своими текстами в каталоге. Остальные получают тексты своего класса: 4xx — 400, 5xx — 500. */
    private const array CODES_WITH_TEXTS = [400, 403, 404, 405, 409, 429, 500];

    private const int CONFLICT = 409;

    private function __construct(
        public int $statusCode,
        public string $title,
        public string $text,
    ) {}

    public static function of(Throwable $exception): self
    {
        $statusCode = $exception instanceof HttpException ? $exception->statusCode : 500;
        $textCode = match (true) {
            in_array($statusCode, self::CODES_WITH_TEXTS, true) => $statusCode,
            $statusCode < 500 => 400,
            default => 500,
        };
        $message = $exception->getMessage();
        $text = $statusCode === self::CONFLICT && $message !== '' ? $message : Yii::t('app', sprintf('error.%d.text', $textCode));

        return new self($statusCode, Yii::t('app', sprintf('error.%d.title', $textCode)), $text);
    }
}
