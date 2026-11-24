<?php

declare(strict_types=1);

// Типы компонентов Yii::$app для phpstan (yii2-extensions/phpstan). Без значений из env:
// анализу нужны только классы.
return [
    'components' => [
        'db' => ['class' => yii\db\Connection::class],
        'redis' => ['class' => yii\redis\Connection::class],
    ],
];
