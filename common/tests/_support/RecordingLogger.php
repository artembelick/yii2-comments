<?php

declare(strict_types=1);

namespace common\tests\_support;

use Override;
use yii\log\Logger;

/**
 * Логгер Yii для проверки «запись в лог»: копит сообщения и никуда их не отправляет.
 * Тест ставит его через Yii::setLogger(), модуль Yii2 сбрасывает логгер после теста.
 */
final class RecordingLogger extends Logger
{
    #[Override]
    public function init(): void
    {
        // Без register_shutdown_function родителя: сбрасывать накопленное некуда.
    }

    /**
     * Уровни сообщений одной категории в порядке записи.
     *
     * @return list<int>
     */
    public function levelsOf(string $category): array
    {
        $messages = array_filter($this->messages, static fn(array $message): bool => $message[2] === $category);

        return array_values(array_map(static fn(array $message): int => $message[1], $messages));
    }
}
