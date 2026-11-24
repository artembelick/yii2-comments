<?php

declare(strict_types=1);

namespace common\tests\_support;

use common\models\User;
use Faker\Generator;
use InvalidArgumentException;
use yii\db\Expression;

/**
 * Генераторы строк для Yii-фикстур: уникальная метка и умолчания для всех колонок.
 * Пара для TS-фабрик e2e/support/factories.ts, имена те же: admin и comment, правило метки то же.
 */
final readonly class FixtureFactory
{
    /** Пароль всех админов из фикстур. Хэш посчитан заранее с cost 4: bcrypt на каждого админа замедлил бы тесты. */
    public const string ADMIN_PASSWORD = 'fixture-admin-password';

    private const string ADMIN_PASSWORD_HASH = '$2y$04$.n5p8cbnXG66WV7ztUSM0.54uCtQeEfFRw61s1F5wUmZQ/IRkb1Ba';

    private const int AUTH_KEY_LENGTH = 32;

    /** Id прогона: одна строка на весь прогон, её генерирует Makefile (docs/test-plans/README.md, «Работа с данными»). */
    private const string RUN_ID = '/^[a-z0-9]{4}$/';

    /** Часть метки из Faker: одинакова во всех прогонах теста (ДТ-2). */
    private const int FAKER_TAG_LENGTH = 4;

    /**
     * @param string $runId TEST_RUN_ID: 4 символа [a-z0-9]
     *
     * @throws InvalidArgumentException TEST_RUN_ID не задан или не того вида
     */
    public function __construct(private Generator $faker, private string $runId)
    {
        if (preg_match(self::RUN_ID, $runId) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'TEST_RUN_ID должен быть из 4 символов [a-z0-9], а получено «%s». Его задаёт Makefile; при запуске codecept напрямую передайте TEST_RUN_ID в окружение.',
                $runId,
            ));
        }
    }

    /**
     * Уникальная метка теста: по ней тест находит свои строки (ИЗ-3). Id прогона отделяет её от меток
     * того же теста в прошлых прогонах на том же стенде (ИЗ-1).
     */
    public function tag(): string
    {
        return $this->runId . $this->faker->regexify(sprintf('[a-z0-9]{%d}', self::FAKER_TAG_LENGTH));
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
