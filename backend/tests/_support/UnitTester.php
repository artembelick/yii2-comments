<?php

declare(strict_types=1);

namespace backend\tests;

use backend\tests\_generated\UnitTesterActions;
use Codeception\Actor;

/**
 * Актор unit-тестов. Действия модулей генерирует Codeception в _generated.
 */
final class UnitTester extends Actor
{
    use UnitTesterActions;
}
