<?php

declare(strict_types=1);

namespace common\tests\_support;

use Codeception\Module;
use Codeception\TestInterface;
use Override;
use Yii;
use yii\di\Container;

/**
 * ИЗ-4: тест подменяет зависимости моками в DI-контейнере Yii, а после теста контейнер создаётся заново.
 * Модуль Yii2 пересоздаёт приложение, но не контейнер.
 */
final class ContainerReset extends Module
{
    #[Override]
    public function _after(TestInterface $test): void
    {
        Yii::$container = new Container();
    }
}
