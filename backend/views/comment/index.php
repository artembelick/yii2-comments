<?php

declare(strict_types=1);

use backend\controllers\CommentController;
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
    'pageSize' => CommentController::PER_PAGE,
    'page' => $page->page - 1,
    'route' => 'comment/index',
    'params' => ['status' => $status->value],
]);
?>
<?php // Настройки клиента админки: их читает page.js. Вид списка нужен, чтобы решить, куда встаёт новый комментарий.?>
<div class="d-flex align-items-center justify-content-between mb-3"
     data-ws-url="<?= Html::encode($wsUrl) ?>"
     data-login-url="<?= Html::encode(Url::to(['auth/login'])) ?>"
     data-status="<?= Html::encode($status->value) ?>"
     data-page="<?= $page->page ?>"
     data-per-page="<?= CommentController::PER_PAGE ?>"
     data-time-zone="<?= Html::encode((string) Yii::$app->formatter->timeZone) ?>">
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

<?php // Баннер «N новых» не на первой странице «Активных»: текст ставит comment-list.js, шаблон ICU plural — в data-pattern.?>
<?= Html::a('', ['comment/index'], [
    'class' => 'alert alert-info d-block mb-3',
    'hidden' => true,
    'aria-live' => 'polite',
    'data-new-banner' => true,
    'data-pattern' => Yii::t('app', 'admin.banner.new'),
]) ?>

<?php // Таблица есть и на пустой вкладке, только скрыта: в неё встаёт комментарий, пришедший по WS.?>
<p class="text-body-secondary" data-empty-state<?= $page->items === [] ? '' : ' hidden' ?>><?= Html::encode(Yii::t('app', 'admin.empty.' . $status->value)) ?></p>
<table class="table align-middle" data-comment-list<?= $page->items === [] ? ' hidden' : '' ?>>
    <thead>
        <tr>
            <th scope="col"><?= Html::encode(Yii::t('app', 'admin.col.author')) ?></th>
            <th scope="col"><?= Html::encode(Yii::t('app', 'admin.col.text')) ?></th>
            <th scope="col"><?= Html::encode(Yii::t('app', 'admin.col.created')) ?></th>
            <th scope="col"><?= Html::encode(Yii::t('app', 'admin.col.audit')) ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($page->items as $comment): ?>
            <?php
            $audit = array_filter([
                $comment->updatedByUser === null ? '' : Yii::t('app', 'admin.audit.updated', ['login' => $comment->updatedByUser->username]),
                $comment->deletedByUser === null ? '' : Yii::t('app', 'admin.audit.deleted', ['login' => $comment->deletedByUser->username]),
            ], static fn(string $line): bool => $line !== '');
            ?>
            <tr data-id="<?= $comment->id ?>">
                <td data-field="author"><?= Html::encode($comment->author) ?></td>
                <td class="comment-text" data-field="text"><?= Html::encode($comment->text) ?></td>
                <td data-field="createdAt"><?= Yii::$app->formatter->asDatetime($comment->created_at) ?></td>
                <td><span class="comment-audit" data-field="audit"<?= $audit === [] ? ' hidden' : '' ?>><?= Html::encode(implode("\n", $audit)) ?></span></td>
            </tr>
        <?php endforeach ?>
    </tbody>
</table>

<?php // Шаблон строки для row-renderer.js: та же разметка, что у строк выше. Тексты аудита — с плейсхолдером {login}.?>
<template data-row-template
          data-text-updated="<?= Html::encode(Yii::t('app', 'admin.audit.updated')) ?>"
          data-text-deleted="<?= Html::encode(Yii::t('app', 'admin.audit.deleted')) ?>">
    <tr data-id="">
        <td data-field="author"></td>
        <td class="comment-text" data-field="text"></td>
        <td data-field="createdAt"></td>
        <td><span class="comment-audit" data-field="audit" hidden></span></td>
    </tr>
</template>

<?= LinkPager::widget(['pagination' => $pagination]) ?>
