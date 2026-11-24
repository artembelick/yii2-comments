<?php

declare(strict_types=1);

use yii\helpers\ArrayHelper;
use yii\web\Application;

/** @var Closure(string): string $env */
$env = require __DIR__ . '/../../common/config/env.php';

defined('YII_DEBUG') || define('YII_DEBUG', $env('YII_DEBUG') === '1');
defined('YII_ENV') || define('YII_ENV', $env('YII_ENV'));

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';
require __DIR__ . '/../../common/config/bootstrap.php';

/** @var array<string, mixed> $common */
$common = require __DIR__ . '/../../common/config/main.php';
/** @var array<string, mixed> $app */
$app = require __DIR__ . '/../config/main.php';

(new Application(ArrayHelper::merge($common, $app)))->run();
