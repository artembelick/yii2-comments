<?php

declare(strict_types=1);

namespace frontend\controllers;

use Override;
use yii\web\Controller;
use yii\web\ErrorAction;

/**
 * Страница ошибки (`errorAction` в config/main.php): свой вид в layout части вместо стандартного шаблона Yii.
 * Что на ней показать, решает common\web\ErrorPage.
 */
final class SiteController extends Controller
{
    #[Override]
    public function actions(): array
    {
        return ['error' => ErrorAction::class];
    }
}
