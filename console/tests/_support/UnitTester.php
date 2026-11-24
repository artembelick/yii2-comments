<?php

declare(strict_types=1);

namespace console\tests;

use Codeception\Actor;
use console\tests\_generated\UnitTesterActions;

/**
 * Актор unit-тестов. Действия модулей генерирует Codeception в _generated.
 */
final class UnitTester extends Actor
{
    use UnitTesterActions;
}
