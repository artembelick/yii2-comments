<?php

declare(strict_types=1);

namespace common\validators;

use Override;
use Yii;
use yii\base\InvalidConfigException;
use yii\validators\Validator;

/**
 * Простой текст от гостя: имя или комментарий, уже нормализованный. Ошибки берутся из каталога по префиксу
 * поля (`name.too_short`, `text.too_long`). Ошибка одна, первая по порядку: тип → обязательность →
 * недопустимые символы → многострочность → длина.
 */
final class PlainTextValidator extends Validator
{
    /** Границы длины обязательны: умолчание молча отклоняло бы ввод (UNIT-VAL-10). */
    public int $min;

    public int $max;

    public bool $multiline = false;

    /** Префикс ключей сообщений: `name` или `text`. */
    public string $messagePrefix = 'text';

    /**
     * Управляющие символы категории Cc, кроме переноса строки (перенос решает multiline), и символы
     * управления направлением текста: ими можно перевернуть соседний текст в ленте и в админке.
     */
    private const string INVALID_CHARS = '/[^\P{Cc}\n]|[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /** Пустое значение тоже проверяется: обязательность и тип решает сам валидатор. */
    public $skipOnEmpty = false;

    #[Override]
    public function init(): void
    {
        parent::init();
        if (!isset($this->min, $this->max)) {
            throw new InvalidConfigException('PlainTextValidator: задайте min и max.');
        }
    }

    /**
     * @return array{string, array<string, mixed>}|null
     */
    #[Override]
    protected function validateValue($value): ?array
    {
        if (!is_string($value)) {
            return [Yii::t('app', 'field.invalid'), []];
        }

        if ($value === '') {
            return $this->error('required');
        }

        if (!mb_check_encoding($value, 'UTF-8') || preg_match(self::INVALID_CHARS, $value) === 1) {
            return $this->error('invalid_chars');
        }

        if (!$this->multiline && str_contains($value, "\n")) {
            return $this->error('multiline');
        }

        $length = mb_strlen($value);
        if ($length < $this->min) {
            return $this->error('too_short');
        }

        if ($length > $this->max) {
            return $this->error('too_long');
        }

        return null;
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function error(string $rule): array
    {
        return [Yii::t('app', $this->messagePrefix . '.' . $rule), []];
    }
}
