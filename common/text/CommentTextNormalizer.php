<?php

declare(strict_types=1);

namespace common\text;

/**
 * Нормализация ввода гостя до валидации: обрезка невидимого по краям. Внутреннее содержимое не меняется.
 */
final readonly class CommentTextNormalizer
{
    /** По краям имени: пробелы любых видов (категория Z) и символы нулевой ширины. */
    private const string NAME_EDGES = '/^[\p{Z}\x{200B}-\x{200D}\x{FEFF}]+|[\p{Z}\x{200B}-\x{200D}\x{FEFF}]+$/u';

    /** По краям текста: то же плюс переносы строк. */
    private const string TEXT_EDGES = '/^[\p{Z}\x{200B}-\x{200D}\x{FEFF}\n]+|[\p{Z}\x{200B}-\x{200D}\x{FEFF}\n]+$/u';

    public function normalizeName(string $value): string
    {
        return $this->trim(self::NAME_EDGES, $value);
    }

    public function normalizeText(string $value): string
    {
        return $this->trim(self::TEXT_EDGES, str_replace(["\r\n", "\r"], "\n", $value));
    }

    /** Невалидный UTF-8 регулярка с /u не разбирает: такая строка возвращается как есть, отклоняет её валидатор. */
    private function trim(string $edges, string $value): string
    {
        return preg_replace($edges, '', $value) ?? $value;
    }
}
