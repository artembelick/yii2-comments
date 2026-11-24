<?php

declare(strict_types=1);

use common\forms\CommentTextForm;
use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var common\models\Comment $comment */
/** @var CommentTextForm $model */

$this->title = Yii::t('app', 'admin.edit.title');
?>
<h1 class="h3 mb-4"><?= Html::encode($this->title) ?></h1>

<?php // Имя автора админ не меняет (GLOSSARY.md): поле disabled — серое, без фокуса, вне формы и браузером не отправляется.?>
<div class="mb-3">
    <label class="form-label" for="comment-author"><?= Html::encode(Yii::t('app', 'admin.edit.author')) ?></label>
    <input class="form-control" id="comment-author" type="text" value="<?= Html::encode($comment->author) ?>" disabled>
</div>

<?php $form = ActiveForm::begin(['id' => 'comment-edit-form', 'action' => ['comment/update', 'id' => $comment->id], 'enableClientValidation' => false]) ?>
    <?= $form->field($model, 'text')->textarea(['value' => is_string($model->text) ? $model->text : '', 'rows' => 6]) ?>
    <?= Html::submitButton(Html::encode(Yii::t('app', 'admin.edit.save')), ['class' => 'btn btn-primary']) ?>
    <?= Html::a(Html::encode(Yii::t('app', 'admin.edit.cancel')), ['comment/index'], ['class' => 'btn btn-link']) ?>
<?php ActiveForm::end() ?>
