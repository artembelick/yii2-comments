<?php

declare(strict_types=1);

namespace common\tests\_support;

use DOMElement;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Что человек видит на HTML-странице ошибки (REG-G-24, REG-A-55): заголовок, текст и ссылки внутри `<main>`.
 * Читает тело ответа, а не crawler PhpBrowser: запросы с любым методом идут через REST, и crawler их не видит.
 */
final class ErrorPageView
{
    /** Строки стандартного шаблона ошибки Yii (`yii2/views/errorHandler/error.php`): своего вида ошибки они не касаются. */
    public const array YII_TEMPLATE_LINES = ['The above error occurred', 'Please contact us'];

    /**
     * @return array{title: string, text: string, links: array<string, string>} ссылки: текст → адрес
     */
    public static function of(string $html): array
    {
        $main = (new Crawler($html))->filter('main');
        $links = [];
        foreach ($main->filter('a') as $link) {
            $links[trim($link->textContent)] = $link instanceof DOMElement ? $link->getAttribute('href') : '';
        }

        return [
            'title' => self::textOf($main->filter('h1')),
            'text' => self::textOf($main->filter('p')),
            'links' => $links,
        ];
    }

    private static function textOf(Crawler $nodes): string
    {
        return $nodes->count() === 0 ? '' : trim($nodes->text());
    }
}
