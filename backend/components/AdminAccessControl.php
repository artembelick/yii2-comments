<?php

declare(strict_types=1);

namespace backend\components;

use Override;
use yii\filters\AccessControl;

/**
 * Общий фильтр доступа админки: по умолчанию действия открыты только вошедшим админам.
 * Гость на HTML-маршруте уходит на логин, а адрес запроса запоминается как returnUrl (User::loginRequired).
 */
final class AdminAccessControl extends AccessControl
{
    #[Override]
    public function init(): void
    {
        if ($this->rules === []) {
            $this->rules = [['allow' => true, 'roles' => ['@']]];
        }

        parent::init();
    }
}
