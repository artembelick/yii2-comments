<?php

declare(strict_types=1);

use common\repositories\CommentStatus;
use common\web\ErrorPage;
use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var Throwable $exception */

$page = ErrorPage::of($exception);
$this->title = $page->title;
// 409 — правка удалённого: вместо «К списку» вкладка «Удалённые», где комментарий можно восстановить.
$back = $page->statusCode === 409
    ? Html::a(Html::encode(Yii::t('app', 'error.back.deleted')), ['comment/index', 'status' => CommentStatus::Deleted->value], ['class' => 'btn btn-primary'])
    : Html::a(Html::encode(Yii::t('app', 'error.back.list')), ['comment/index'], ['class' => 'btn btn-primary']);
?>
<h1 class="h3 mb-3"><?= Html::encode($page->title) ?></h1>
<p class="mb-4"><?= Html::encode($page->text) ?></p>
<?= $back ?>
