<?php

declare(strict_types=1);

namespace common\tests\_support;

use Codeception\Example;
use UnexpectedValueException;

/**
 * Типизированный доступ к набору провайдера Cest: Codeception отдаёт значения как mixed.
 */
final class Examples
{
    /**
     * @param Example<string, mixed> $example
     */
    public static function string(Example $example, string $key): string
    {
        $value = $example[$key];

        return is_string($value) ? $value : throw new UnexpectedValueException(sprintf('В наборе провайдера %s — не строка', $key));
    }
}
