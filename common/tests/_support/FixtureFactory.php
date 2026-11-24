<?php

declare(strict_types=1);

namespace common\tests\_support;

use common\models\User;
use Faker\Generator;
use yii\db\Expression;

/**
 * Генераторы строк для Yii-фикстур: уникальная метка и умолчания для всех колонок.
 * Пара для TS-фабрик e2e/support/factories.ts, имена те же: admin и comment.
 */
final readonly class FixtureFactory
{
    /** Пароль всех админов из фикстур. Хэш посчитан заранее с cost 4: bcrypt на каждого админа замедлил бы тесты. */
    public const string ADMIN_PASSWORD = 'fixture-admin-password';

    private const string ADMIN_PASSWORD_HASH = '$2y$04$.n5p8cbnXG66WV7ztUSM0.54uCtQeEfFRw61s1F5wUmZQ/IRkb1Ba';

    private const int AUTH_KEY_LENGTH = 32;

    private const int TAG_LENGTH = 4;

    public function __construct(private Generator $faker) {}

    /** Уникальная метка теста: по ней тест находит свои строки (ИЗ-3). */
    public function tag(): string
    {
        return $this->faker->regexify(sprintf('[a-z0-9]{%d}', self::TAG_LENGTH));
    }

    /**
     * Поля строки таблицы user, кроме id из последовательности.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public function adminRow(array $overrides = []): array
    {
        return array_merge([
            'username' => 'admin-' . $this->tag(),
            'password_hash' => self::ADMIN_PASSWORD_HASH,
            'auth_key' => $this->faker->regexify(sprintf('[A-Za-z0-9]{%d}', self::AUTH_KEY_LENGTH)),
            'status' => User::STATUS_ACTIVE,
            'created_at' => new Expression('now()'),
            'updated_at' => new Expression('now()'),
        ], $overrides);
    }

    /**
     * Поля строки таблицы comment, кроме id. Дата по умолчанию — текущее время БД.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public function commentRow(array $overrides = []): array
    {
        $tag = $this->tag();

        return array_merge([
            'author' => $this->faker->firstName() . ' ' . $tag,
            'text' => $this->faker->realText(60) . ' ' . $tag,
            'created_at' => new Expression('now()'),
            'updated_at' => null,
            'updated_by' => null,
            'deleted_at' => null,
            'deleted_by' => null,
        ], $overrides);
    }
}
