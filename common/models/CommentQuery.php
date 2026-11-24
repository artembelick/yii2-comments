<?php

declare(strict_types=1);

namespace common\models;

use yii\db\ActiveQuery;

/**
 * Выборки комментариев. Порядок везде один: новые сверху, при равной дате — больший id.
 *
 * @extends ActiveQuery<Comment>
 */
final class CommentQuery extends ActiveQuery
{
    public function active(): self
    {
        return $this->andWhere(['deleted_at' => null]);
    }

    public function deleted(): self
    {
        return $this->andWhere(['not', ['deleted_at' => null]]);
    }

    public function newestFirst(): self
    {
        return $this->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC]);
    }
}
