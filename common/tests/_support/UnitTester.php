<?php

declare(strict_types=1);

namespace common\tests;

use Codeception\Actor;
use common\tests\_generated\UnitTesterActions;

/**
 * Актор unit-тестов. Действия модулей генерирует Codeception в _generated.
 */
final class UnitTester extends Actor
{
    use UnitTesterActions;
}
