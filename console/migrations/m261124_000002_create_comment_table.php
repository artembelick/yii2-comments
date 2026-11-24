<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Комментарии (docs/test-plans/unit.md, «Схема»). Удаление мягкое: deleted_at и deleted_by заданы вместе или пусты вместе.
 */
final class m261124_000002_create_comment_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%comment}}', [
            'id' => $this->bigPrimaryKey(),
            'author' => $this->string(64)->notNull(),
            'text' => $this->string(2000)->notNull(),
            'created_at' => 'timestamptz NOT NULL',
            'updated_at' => 'timestamptz NULL',
            'updated_by' => $this->integer()->null(),
            'deleted_at' => 'timestamptz NULL',
            'deleted_by' => $this->integer()->null(),
        ]);
        $this->addForeignKey('comment_updated_by_fk', '{{%comment}}', 'updated_by', '{{%user}}', 'id');
        $this->addForeignKey('comment_deleted_by_fk', '{{%comment}}', 'deleted_by', '{{%user}}', 'id');
        $this->execute('ALTER TABLE {{%comment}} ADD CONSTRAINT comment_deleted_pair_check CHECK ((deleted_at IS NULL) = (deleted_by IS NULL))');
        $this->execute('CREATE INDEX comment_status_order_idx ON {{%comment}} (deleted_at, created_at DESC, id DESC)');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%comment}}');
    }
}
