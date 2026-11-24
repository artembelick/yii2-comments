<?php

declare(strict_types=1);

namespace common\forms;

use common\text\CommentTextNormalizer;
use common\validators\PlainTextValidator;
use Override;
use Yii;
use yii\base\Model;

/**
 * Форма комментария гостя. Поля приходят как есть: строкой, массивом или не приходят вовсе, поэтому
 * они не типизированы — тип проверяет валидатор. Перед валидацией строки нормализуются.
 */
final class CommentForm extends Model
{
    public mixed $name = '';

    public mixed $text = '';

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly CommentTextNormalizer $normalizer, array $config = [])
    {
        parent::__construct($config);
    }

    /** Поля формы без обёртки: `name`, `text`. */
    #[Override]
    public function formName(): string
    {
        return '';
    }

    #[Override]
    public function rules(): array
    {
        return [
            ['name', PlainTextValidator::class, 'min' => 2, 'max' => 64, 'multiline' => false, 'messagePrefix' => 'name'],
            ['text', PlainTextValidator::class, 'min' => 1, 'max' => 2000, 'multiline' => true, 'messagePrefix' => 'text'],
        ];
    }

    #[Override]
    public function attributeLabels(): array
    {
        return [
            'name' => Yii::t('app', 'form.name'),
            'text' => Yii::t('app', 'form.text'),
        ];
    }

    #[Override]
    public function beforeValidate(): bool
    {
        if (is_string($this->name)) {
            $this->name = $this->normalizer->normalizeName($this->name);
        }

        if (is_string($this->text)) {
            $this->text = $this->normalizer->normalizeText($this->text);
        }

        return parent::beforeValidate();
    }

    /** Имя после нормализации. Значение не строкой (форма невалидна) — пустая строка. */
    public function getNormalizedName(): string
    {
        return is_string($this->name) ? $this->normalizer->normalizeName($this->name) : '';
    }

    /** Текст после нормализации. Значение не строкой (форма невалидна) — пустая строка. */
    public function getNormalizedText(): string
    {
        return is_string($this->text) ? $this->normalizer->normalizeText($this->text) : '';
    }
}
