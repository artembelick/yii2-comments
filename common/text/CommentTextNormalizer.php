<?php

declare(strict_types=1);

namespace common\text;

use RuntimeException;

/**
 * Нормализация ввода гостя и админа до валидации, единственное место очистки: битые байты, управляющие и
 * bidi-символы вырезаются, табуляция и переносы приводятся к пробелу или `\n`, затем невидимое обрезается по краям.
 * Внутренние пробелы не схлопываются. Правила — docs/test-plans/unit.md, «Правила очистки».
 */
final readonly class CommentTextNormalizer
{
    /** По краям имени: пробелы любых видов (категория Z) и символы нулевой ширины. */
    private const string NAME_EDGES = '/^[\p{Z}\x{200B}-\x{200D}\x{FEFF}]+|[\p{Z}\x{200B}-\x{200D}\x{FEFF}]+$/u';

    /** По краям текста: то же плюс переносы строк. */
    private const string TEXT_EDGES = '/^[\p{Z}\x{200B}-\x{200D}\x{FEFF}\n]+|[\p{Z}\x{200B}-\x{200D}\x{FEFF}\n]+$/u';

    /**
     * Вырезаются: управляющие символы категории Cc, кроме пробельных — их заменяют отдельно, — и символы
     * управления направлением текста вместе с марками LRM, RLM и ALM: ими можно перевернуть или переставить
     * соседний текст в ленте и в админке.
     */
    private const string STRIPPED = '/[^\P{Cc}\t\n\r]|[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}\x{061C}]/u';

    /** Переносы строк любого вида: в имени становятся пробелом, в тексте — `\n`. */
    private const string LINE_BREAKS = '/\r\n|[\n\r\x{2028}\x{2029}]/u';

    public function normalizeName(string $value): string
    {
        return $this->trim(self::NAME_EDGES, $this->clean($value, ' '));
    }

    public function normalizeText(string $value): string
    {
        return $this->trim(self::TEXT_EDGES, $this->clean($value, "\n"));
    }

    /**
     * Битые байты вырезаются, переносы приводятся к `$lineBreak`, затем вырезаются управляющие символы и табуляция
     * становится пробелом. Переносы распознаются до вырезания: `\r\0\n` — два переноса, а не один `\r\n`.
     */
    private function clean(string $value, string $lineBreak): string
    {
        $unifiedBreaks = $this->replace(self::LINE_BREAKS, $lineBreak, $this->scrub($value));

        return str_replace("\t", ' ', $this->replace(self::STRIPPED, '', $unifiedBreaks));
    }

    /** Битые байты UTF-8 вырезаются без символа замены: после этого строку разбирают регулярки с /u. */
    private function scrub(string $value): string
    {
        $substitute = mb_substitute_character();
        mb_substitute_character('none');
        $scrubbed = mb_scrub($value, 'UTF-8');
        mb_substitute_character($substitute);

        return $scrubbed;
    }

    private function trim(string $edges, string $value): string
    {
        return $this->replace($edges, '', $value);
    }

    /**
     * Строка уже без битых байтов, поэтому регулярка с /u её разбирает. Сбой PCRE (лимиты backtrack или JIT)
     * не превращается молча в пустую строку и ошибку `required`: это ошибка сервера.
     */
    private function replace(string $pattern, string $replacement, string $value): string
    {
        return preg_replace($pattern, $replacement, $value) ?? throw new RuntimeException('Сбой PCRE при нормализации: ' . preg_last_error_msg());
    }
}
