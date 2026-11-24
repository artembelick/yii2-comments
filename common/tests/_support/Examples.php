<?php

declare(strict_types=1);

namespace common\tests\_support;

use Codeception\Example;
use common\repositories\CommentStatus;
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

    /**
     * @param Example<string, mixed> $example
     */
    public static function int(Example $example, string $key): int
    {
        $value = $example[$key];

        return is_int($value) ? $value : throw new UnexpectedValueException(sprintf('В наборе провайдера %s — не целое', $key));
    }

    /**
     * @param Example<string, mixed> $example
     *
     * @return array<mixed>
     */
    public static function array(Example $example, string $key): array
    {
        $value = $example[$key];

        return is_array($value) ? $value : throw new UnexpectedValueException(sprintf('В наборе провайдера %s — не массив', $key));
    }

    /**
     * @param Example<string, mixed> $example
     *
     * @return list<string>
     */
    public static function strings(Example $example, string $key): array
    {
        $value = self::array($example, $key);

        return array_is_list($value) && array_filter($value, is_string(...)) === $value
            ? $value
            : throw new UnexpectedValueException(sprintf('В наборе провайдера %s — не список строк', $key));
    }

    /**
     * @param Example<string, mixed> $example
     */
    public static function commentStatus(Example $example, string $key): CommentStatus
    {
        $value = $example[$key];

        return $value instanceof CommentStatus ? $value : throw new UnexpectedValueException(sprintf('В наборе провайдера %s — не CommentStatus', $key));
    }
}
