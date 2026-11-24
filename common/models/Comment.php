<?php

declare(strict_types=1);

namespace common\models;

use Override;
use yii\db\ActiveRecord;

/**
 * Комментарий гостя в ленте (GLOSSARY.md). Удаление мягкое: deleted_at и deleted_by.
 *
 * @property int $id
 * @property string $author
 * @property string $text
 * @property string $created_at
 * @property string|null $updated_at
 * @property int|null $updated_by
 * @property string|null $deleted_at
 * @property int|null $deleted_by
 */
final class Comment extends ActiveRecord
{
    #[Override]
    public static function tableName(): string
    {
        return '{{%comment}}';
    }

    #[Override]
    public static function find(): CommentQuery
    {
        return new CommentQuery(self::class);
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
