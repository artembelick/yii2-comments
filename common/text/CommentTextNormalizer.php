<?php

declare(strict_types=1);

namespace common\text;

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
     * управления направлением текста: ими можно перевернуть соседний текст в ленте и в админке.
     */
    private const string STRIPPED = '/[^\P{Cc}\t\n\r]|[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /** Переносы строк любого вида: в имени становятся пробелом, в тексте — `\n`. */
    private const string LINE_BREAKS = '/\r\n|[\n\r\x{2028}\x{2029}]/u';

    public function normalizeName(string $value): string
    {
        return $this->trim(self::NAME_EDGES, $this->replace(self::LINE_BREAKS, ' ', $this->clean($value)));
    }

    public function normalizeText(string $value): string
    {
        return $this->trim(self::TEXT_EDGES, $this->replace(self::LINE_BREAKS, "\n", $this->clean($value)));
    }

    /** Битые байты и управляющие символы вырезаются, табуляция становится пробелом. */
    private function clean(string $value): string
    {
        return str_replace("\t", ' ', $this->replace(self::STRIPPED, '', $this->scrub($value)));
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

    /** Строка уже без битых байтов, поэтому регулярка с /u её разбирает. */
    private function replace(string $pattern, string $replacement, string $value): string
    {
        return (string) preg_replace($pattern, $replacement, $value);
    }
}
