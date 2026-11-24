<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Админы (docs/test-plans/unit.md, «Схема»). Логин уникален без учёта регистра.
 */
final class m261124_000001_create_user_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%user}}', [
            'id' => $this->primaryKey(),
            'username' => $this->string(64)->notNull(),
            'password_hash' => $this->string(255)->notNull(),
            'auth_key' => $this->string(32)->notNull(),
            'status' => $this->smallInteger()->notNull(),
            'created_at' => 'timestamptz NOT NULL',
            'updated_at' => 'timestamptz NOT NULL',
        ]);
        $this->execute('CREATE UNIQUE INDEX user_username_lower_unique ON {{%user}} (lower(username))');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%user}}');
    }
}
