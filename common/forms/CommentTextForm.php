<?php

declare(strict_types=1);

namespace common\forms;

use common\text\CommentTextNormalizer;
use common\validators\PlainTextValidator;
use Override;
use Yii;
use yii\base\Model;

/**
 * Форма правки текста комментария в админке. Имя автора админ не меняет (GLOSSARY.md), поэтому поле одно.
 * Правила текста те же, что у формы гостя (CommentForm): поле приходит как есть, тип проверяет валидатор.
 */
final class CommentTextForm extends Model
{
    public mixed $text = '';

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly CommentTextNormalizer $normalizer, array $config = [])
    {
        parent::__construct($config);
    }

    /** Поле формы без обёртки: `text`. */
    #[Override]
    public function formName(): string
    {
        return '';
    }

    #[Override]
    public function rules(): array
    {
        return [
            ['text', PlainTextValidator::class, 'min' => 1, 'max' => 2000, 'multiline' => true, 'messagePrefix' => 'text'],
        ];
    }

    #[Override]
    public function attributeLabels(): array
    {
        return ['text' => Yii::t('app', 'admin.edit.text')];
    }

    #[Override]
    public function beforeValidate(): bool
    {
        if (is_string($this->text)) {
            $this->text = $this->normalizer->normalizeText($this->text);
        }

        return parent::beforeValidate();
    }

    /** Текст после нормализации. Значение не строкой (форма невалидна) — пустая строка. */
    public function getNormalizedText(): string
    {
        return is_string($this->text) ? $this->normalizer->normalizeText($this->text) : '';
    }

    /** Ввод после нормализации совпадает с текущим текстом: пробелы по краям и `\r\n` правкой не считаются. */
    public function isSameAs(string $currentText): bool
    {
        return $this->getNormalizedText() === $currentText;
    }
}
