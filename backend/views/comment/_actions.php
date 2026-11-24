<?php

declare(strict_types=1);

use common\repositories\CommentStatus;
use yii\bootstrap5\Html;

/**
 * Действия строки списка: у активного «Редактировать» и «Удалить», у удалённого «Восстановить».
 * Удаление и восстановление — обычные POST-формы с CSRF-полем; подтверждение показывает yii.js по data-confirm.
 * Скрытые status и page возвращают админа после действия на ту же вкладку и страницу.
 *
 * @var string $id id комментария или `{id}` в шаблоне строки для row-renderer.js
 * @var list<CommentStatus> $statuses для каких статусов блоки действий: в строке — один, в шаблоне — оба
 * @var CommentStatus $tab вкладка списка
 * @var int $page страница списка
 */
$backTo = Html::hiddenInput('status', $tab->value) . Html::hiddenInput('page', (string) $page);
?>
<?php if (in_array(CommentStatus::Active, $statuses, true)): ?>
    <span class="d-inline-flex gap-2" data-actions="active">
        <?= Html::a(Html::encode(Yii::t('app', 'admin.action.edit')), '/comments/' . $id . '/edit', ['class' => 'btn btn-outline-primary btn-sm']) ?>
        <?= Html::beginForm('/comments/' . $id . '/delete', 'post') ?>
            <?= $backTo ?>
            <?= Html::submitButton(Html::encode(Yii::t('app', 'admin.action.delete')), [
                'class' => 'btn btn-outline-danger btn-sm',
                'data-confirm' => Yii::t('app', 'admin.confirm.delete'),
            ]) ?>
        <?= Html::endForm() ?>
    </span>
<?php endif ?>
<?php if (in_array(CommentStatus::Deleted, $statuses, true)): ?>
    <span class="d-inline-flex gap-2" data-actions="deleted">
        <?= Html::beginForm('/comments/' . $id . '/restore', 'post') ?>
            <?= $backTo ?>
            <?= Html::submitButton(Html::encode(Yii::t('app', 'admin.action.restore')), [
                'class' => 'btn btn-outline-success btn-sm',
                'data-confirm' => Yii::t('app', 'admin.confirm.restore'),
            ]) ?>
        <?= Html::endForm() ?>
    </span>
<?php endif ?>
