<?php

declare(strict_types=1);

namespace common\validators;

use Override;
use Yii;
use yii\base\InvalidConfigException;
use yii\validators\Validator;

/**
 * Простой текст от гостя: имя или комментарий, уже нормализованный и очищенный (CommentTextNormalizer), поэтому
 * символы валидатор не проверяет. Ошибки берутся из каталога по префиксу поля (`name.too_short`, `text.too_long`).
 * Ошибка одна, первая по порядку: тип → обязательность → длина.
 */
final class PlainTextValidator extends Validator
{
    /** Границы длины обязательны: умолчание молча отклоняло бы ввод (UNIT-VAL-10). */
    public int $min;

    public int $max;

    /** Префикс ключей сообщений: `name` или `text`. */
    public string $messagePrefix = 'text';

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
