<?php

declare(strict_types=1);

use backend\forms\LoginForm;
use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var LoginForm $model */

$this->title = Yii::t('app', 'login.title');
?>
<div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">
        <h1 class="h3 mb-4"><?= Html::encode($this->title) ?></h1>

        <?php $form = ActiveForm::begin(['id' => 'login-form']); ?>
            <?= $form->field($model, 'username')->textInput(['autofocus' => true, 'autocomplete' => 'username']) ?>
            <?= $form->field($model, 'password')->passwordInput(['autocomplete' => 'current-password']) ?>
            <?= Html::submitButton(Yii::t('app', 'login.submit'), ['class' => 'btn btn-primary w-100']) ?>
        <?php ActiveForm::end(); ?>
    </div>
</div>
