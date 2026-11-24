<?php

declare(strict_types=1);

namespace ws\runtime;

use Override;
use Workerman\Timer;
use ws\TimerInterface;

/** Одноразовые таймеры event loop Workerman. Логики нет: entrypoint-limits. */
final class WorkermanTimer implements TimerInterface
{
    #[Override]
    public function after(float $seconds, callable $fn): int
    {
        return Timer::add($seconds, $fn, [], false);
    }

    #[Override]
    public function cancel(int $timerId): void
    {
        Timer::del($timerId);
    }
}
