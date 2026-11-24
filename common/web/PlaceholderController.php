<?php

declare(strict_types=1);

namespace common\web;

use yii\web\Controller;

/**
 * Временная заглушка каркаса: отвечает 200 на корне обеих частей, пока срезы не принесли настоящие маршруты.
 * Удаляется в срезах 02 (админка) и 03 (публичная часть) вместе с UNIT-SKEL-01.
 */
final class PlaceholderController extends Controller
{
    public function actionIndex(): string
    {
        return 'Каркас работает';
    }
}
