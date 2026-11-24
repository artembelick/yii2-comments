<?php

declare(strict_types=1);

namespace ws\tests\_support;

use Override;
use ws\TimerInterface;

/** Таймеры без реального времени: тест видит, что запланировано, и сам «дожидается» нужной задержки. */
final class FakeTimer implements TimerInterface
{
    private int $lastId = 0;

    /** @var array<int, array{seconds: float, fn: callable(): void}> */
    private array $pending = [];

    /** @var list<float> */
    private array $scheduled = [];

    #[Override]
    public function after(float $seconds, callable $fn): int
    {
        $this->pending[++$this->lastId] = ['seconds' => $seconds, 'fn' => $fn];
        $this->scheduled[] = $seconds;

        return $this->lastId;
    }

    #[Override]
    public function cancel(int $timerId): void
    {
        unset($this->pending[$timerId]);
    }

    /**
     * Задержки запланированных и не отменённых таймеров в порядке постановки.
     *
     * @return list<float>
     */
    public function pendingDelays(): array
    {
        return array_values(array_map(static fn(array $timer): float => $timer['seconds'], $this->pending));
    }

    /**
     * Задержки всех таймеров, когда-либо поставленных, в порядке постановки: и отменённых, и сработавших.
     *
     * @return list<float>
     */
    public function scheduledDelays(): array
    {
        return $this->scheduled;
    }

    /** Срабатывают все запланированные таймеры с этой задержкой. */
    public function elapse(float $seconds): void
    {
        $due = array_filter($this->pending, static fn(array $timer): bool => $timer['seconds'] === $seconds);
        $this->pending = array_diff_key($this->pending, $due);
        foreach ($due as $timer) {
            ($timer['fn'])();
        }
    }
}
