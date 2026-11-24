<?php

declare(strict_types=1);

namespace backend\tests;

use backend\tests\_generated\RegressionTesterActions;
use Codeception\Actor;

/**
 * Актор REST-regression. Действия модулей генерирует Codeception в _generated.
 */
final class RegressionTester extends Actor
{
    use RegressionTesterActions;
}
