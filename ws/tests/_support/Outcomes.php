<?php

declare(strict_types=1);

namespace ws\tests\_support;

use Closure;
use Throwable;

/** Ответы мока по очереди: значение возвращается, исключение выбрасывается. В PHPUnit это делает только callback. */
final class Outcomes
{
    public static function inOrder(mixed ...$outcomes): Closure
    {
        return static function () use (&$outcomes): mixed {
            $next = array_shift($outcomes);
            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        };
    }
}
