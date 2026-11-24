<?php

declare(strict_types=1);

use common\web\ErrorPage;
use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var Throwable $exception */

$page = ErrorPage::of($exception);
$this->title = $page->title;
?>
<h1 class="h3 mb-3"><?= Html::encode($page->title) ?></h1>
<p class="mb-4"><?= Html::encode($page->text) ?></p>
<?= Html::a(Html::encode(Yii::t('app', 'error.back.home')), Yii::$app->homeUrl, ['class' => 'btn btn-primary']) ?>
