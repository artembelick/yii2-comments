<?php

declare(strict_types=1);

use backend\assets\AppAsset;
use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var string $content */

AppAsset::register($this);
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Html::encode(Yii::$app->language) ?>" class="h-100">
<head>
    <meta charset="<?= Html::encode(Yii::$app->charset) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $this->registerCsrfMetaTags() ?>
    <title><?= Html::encode($this->title) ?></title>
    <?php $this->head() ?>
</head>
<body class="d-flex flex-column h-100">
<?php $this->beginBody() ?>
<?php if (!Yii::$app->user->getIsGuest()): ?>
    <header class="border-bottom">
        <div class="container py-2 d-flex justify-content-end">
            <?= Html::beginForm(['auth/logout'], 'post', ['id' => 'logout-form']) ?>
                <?= Html::submitButton(Html::encode(Yii::t('app', 'admin.logout')), ['class' => 'btn btn-outline-secondary btn-sm']) ?>
            <?= Html::endForm() ?>
        </div>
    </header>
<?php endif ?>
<main class="flex-shrink-0">
    <div class="container py-4">
        <?= $content ?>
    </div>
</main>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage();
