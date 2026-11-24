<?php

declare(strict_types=1);

use common\repositories\CommentPage;
use common\repositories\CommentStatus;
use yii\bootstrap5\Html;
use yii\bootstrap5\LinkPager;
use yii\data\Pagination;
use yii\helpers\Url;

/** @var yii\web\View $this */
/** @var CommentStatus $status */
/** @var CommentPage $page */

$this->title = Yii::t('app', 'admin.title');
$tabs = [
    CommentStatus::Active->value => Yii::t('app', 'admin.tab.active'),
    CommentStatus::Deleted->value => Yii::t('app', 'admin.tab.deleted'),
];
/** @var string $wsUrl адрес из params, см. backend/config/main.php */
$wsUrl = Yii::$app->params['wsUrl'];
$this->registerJsFile('@web/js/main.js', ['type' => 'module']);
$pagination = new Pagination([
    'totalCount' => $page->totalCount,
    'pageSize' => backend\controllers\CommentController::PER_PAGE,
    'page' => $page->page - 1,
    'route' => 'comment/index',
    'params' => ['status' => $status->value],
]);
?>
<div class="d-flex align-items-center justify-content-between mb-3"
     data-ws-url="<?= Html::encode($wsUrl) ?>"
     data-login-url="<?= Html::encode(Url::to(['auth/login'])) ?>">
    <h1 class="h3 mb-0"><?= Html::encode($this->title) ?></h1>
    <?php // Без JS индикатор честно показывает «Отключено»: соединение открывает main.js.?>
    <span class="ws-status" role="status" aria-label="<?= Html::encode(Yii::t('app', 'ws.status.label')) ?>"
          data-ws-status data-state="offline"
          data-text-online="<?= Html::encode(Yii::t('app', 'ws.status.online')) ?>"
          data-text-reconnecting="<?= Html::encode(Yii::t('app', 'ws.status.reconnecting')) ?>"
          data-text-offline="<?= Html::encode(Yii::t('app', 'ws.status.offline')) ?>"><?= Html::encode(Yii::t('app', 'ws.status.offline')) ?></span>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach ($tabs as $value => $label): ?>
        <li class="nav-item">
            <?= Html::a(Html::encode($label), ['comment/index', 'status' => $value], [
                'class' => ['nav-link', $value === $status->value ? 'active' : ''],
                'aria-current' => $value === $status->value ? 'page' : null,
            ]) ?>
        </li>
    <?php endforeach ?>
</ul>

<?php if ($page->items === []): ?>
    <p class="text-body-secondary"><?= Html::encode(Yii::t('app', 'admin.empty.' . $status->value)) ?></p>
<?php else: ?>
    <table class="table align-middle">
        <thead>
            <tr>
                <th scope="col"><?= Html::encode(Yii::t('app', 'admin.col.author')) ?></th>
                <th scope="col"><?= Html::encode(Yii::t('app', 'admin.col.text')) ?></th>
                <th scope="col"><?= Html::encode(Yii::t('app', 'admin.col.created')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($page->items as $comment): ?>
                <tr data-id="<?= $comment->id ?>">
                    <td><?= Html::encode($comment->author) ?></td>
                    <td class="comment-text"><?= Html::encode($comment->text) ?></td>
                    <td><?= Yii::$app->formatter->asDatetime($comment->created_at) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
<?php endif ?>

<?= LinkPager::widget(['pagination' => $pagination]) ?>
