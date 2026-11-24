<?php

declare(strict_types=1);

use frontend\controllers\CommentController;
use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;
use yii\bootstrap5\LinkPager;
use yii\data\Pagination;

/** @var yii\web\View $this */
/** @var common\repositories\CommentPage $page */
/** @var common\forms\CommentForm $model */
/** @var string|null $alert */

$this->title = Yii::t('app', 'feed.title');
$pagination = new Pagination([
    'totalCount' => $page->totalCount,
    'pageSize' => CommentController::PER_PAGE,
    'page' => $page->page - 1,
    'route' => 'comment/index',
]);
?>
<h1 id="feed-title" class="h3 mb-4"><?= Html::encode($this->title) ?></h1>

<?php if (is_string($flash = Yii::$app->session->getFlash('success'))): ?>
    <div class="alert alert-success" role="status"><?= Html::encode($flash) ?></div>
<?php endif ?>
<?php if ($alert !== null): ?>
    <div class="alert alert-danger" role="alert"><?= Html::encode($alert) ?></div>
<?php endif ?>

<section class="mb-5" aria-labelledby="form-title">
    <h2 id="form-title" class="h5"><?= Html::encode(Yii::t('app', 'form.title')) ?></h2>
    <?php $form = ActiveForm::begin(['id' => 'comment-form', 'action' => ['comment/create'], 'enableClientValidation' => false]) ?>
        <?= $form->field($model, 'name')->textInput(['value' => is_string($model->name) ? $model->name : '', 'maxlength' => false]) ?>
        <?= $form->field($model, 'text')->textarea(['value' => is_string($model->text) ? $model->text : '', 'rows' => 4]) ?>
        <?= Html::submitButton(Html::encode(Yii::t('app', 'form.submit')), ['class' => 'btn btn-primary']) ?>
    <?php ActiveForm::end() ?>
</section>

<section aria-labelledby="feed-title">
    <?php if ($page->items === []): ?>
        <p class="text-body-secondary"><?= Html::encode(Yii::t('app', 'feed.empty')) ?></p>
    <?php else: ?>
        <ol class="list-unstyled">
            <?php foreach ($page->items as $comment): ?>
                <li class="mb-3 border-bottom pb-2" data-id="<?= $comment->id ?>">
                    <div>
                        <strong class="comment-author"><?= Html::encode($comment->author) ?></strong>
                        <time class="text-body-secondary small ms-2" datetime="<?= Yii::$app->formatter->asDatetime($comment->created_at, 'php:c') ?>"><?= Yii::$app->formatter->asDatetime($comment->created_at) ?></time>
                    </div>
                    <div class="comment-text"><?= Html::encode($comment->text) ?></div>
                </li>
            <?php endforeach ?>
        </ol>
    <?php endif ?>
</section>

<?= LinkPager::widget([
    'pagination' => $pagination,
    'options' => ['aria-label' => Yii::t('app', 'pager.label')],
    'prevPageLabel' => Html::encode(Yii::t('app', 'pager.prev')),
    'nextPageLabel' => Html::encode(Yii::t('app', 'pager.next')),
]) ?>
