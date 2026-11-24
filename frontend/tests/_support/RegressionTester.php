<?php

declare(strict_types=1);

namespace frontend\tests;

use Codeception\Actor;
use frontend\tests\_generated\RegressionTesterActions;

/**
 * Актор REST-regression. Действия модулей генерирует Codeception в _generated.
 */
final class RegressionTester extends Actor
{
    use RegressionTesterActions;
}
