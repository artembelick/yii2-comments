<?php

declare(strict_types=1);

namespace backend\components;

use Override;
use Yii;
use yii\filters\AccessControl;
use yii\web\Response;
use yii\web\User;

/**
 * Общий фильтр доступа админки: по умолчанию действия открыты только вошедшим админам.
 * Гость на HTML-маршруте уходит на логин, а адрес запроса запоминается как returnUrl (User::loginRequired).
 * Гость на JSON-маршруте /api/* получает 401.
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

    /**
     * Гость на JSON-маршруте /api/* получает 401 с JSON, а не редирект на HTML-страницу логина:
     * клиент админки по этому ответу уводит на логин сам (docs/test-plans/regression.md, REG-A-21).
     *
     * @param false|User $user
     */
    #[Override]
    protected function denyAccess($user): void
    {
        if ($user instanceof User && $user->getIsGuest() && str_starts_with(Yii::$app->request->getPathInfo(), 'api/')) {
            $response = Yii::$app->response;
            $response->format = Response::FORMAT_JSON;
            $response->statusCode = 401;
            $response->data = ['error' => 'unauthorized'];

            return;
        }

        parent::denyAccess($user);
    }
}
