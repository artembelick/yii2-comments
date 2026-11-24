<?php

declare(strict_types=1);

namespace ws;

/** Одноразовые таймеры event loop. Реализация над Workerman Timer живёт в ws/runtime. */
interface TimerInterface
{
    /**
     * @param callable(): void $fn
     *
     * @return int id таймера для cancel
     */
    public function after(float $seconds, callable $fn): int;

    public function cancel(int $timerId): void;
}
