<?php

declare(strict_types=1);

namespace common\tests\_support;

use Override;
use yii\db\Connection;
use yii\test\ActiveFixture;

/**
 * Yii ActiveFixture с данными, сгенерированными на лету (docs/test-plans/README.md, «Работа с данными»).
 * БД стенда общая на весь прогон, поэтому таблица не очищается ни перед загрузкой, ни после теста,
 * а последовательность id не сбрасывается.
 */
final class GeneratedFixture extends ActiveFixture
{
    /** @var array<string, array<string, mixed>> строки по алиасам */
    public array $rows = [];

    #[Override]
    public function load(): void
    {
        $this->data = [];
        $table = $this->getTableSchema();
        $db = $this->db;
        assert($db instanceof Connection);
        foreach ($this->rows as $alias => $row) {
            $primaryKeys = $db->getSchema()->insert($table->fullName, $row);
            $this->data[$alias] = array_merge($row, (array) $primaryKeys);
        }
    }

    #[Override]
    public function unload(): void
    {
        $this->data = [];
    }
}
