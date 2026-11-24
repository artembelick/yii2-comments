<?php

declare(strict_types=1);

namespace common\tests\_support;

use Codeception\Module;
use Codeception\Module\Yii2;
use Codeception\TestInterface;
use common\models\Comment;
use common\models\User;
use Faker\Factory;
use Override;
use RuntimeException;
use Yii;

/**
 * Данные-предусловия для тестов на БД: строки из FixtureFactory вставляются Yii-фикстурами
 * через модуль Yii2. Faker получает seed из ID и имени теста (TestSeed), метка начинается с TEST_RUN_ID из окружения.
 * Seed и TEST_RUN_ID попадают в отчёт о падении (ДТ-2).
 */
final class FixturesModule extends Module
{
    private ?FixtureFactory $factory = null;

    private int $seed = 0;

    private string $runId = '';

    private int $loaded = 0;

    #[Override]
    public function _before(TestInterface $test): void
    {
        $this->seed = TestSeed::of($test);
        $faker = Factory::create('ru_RU');
        $faker->seed($this->seed);

        $this->runId = (string) getenv('TEST_RUN_ID');
        $this->factory = new FixtureFactory($faker, $this->runId);
        $this->loaded = 0;
    }

    #[Override]
    public function _failed(TestInterface $test, mixed $fail): void
    {
        $test->getMetadata()->addReport('faker-seed', (string) $this->seed);
        $test->getMetadata()->addReport('test-run-id', $this->runId);
    }

    public function fixtureFactory(): FixtureFactory
    {
        return $this->factory ?? throw new RuntimeException('FixturesModule не инициализирован: нет _before');
    }

    /**
     * Админ с уникальным логином и паролем FixtureFactory::ADMIN_PASSWORD.
     *
     * @param array<string, mixed> $overrides
     */
    public function haveAdmin(array $overrides = []): User
    {
        $admin = $this->load(User::class, $this->fixtureFactory()->adminRow($overrides));

        return $admin instanceof User ? $admin : throw new RuntimeException('Фикстура admin вернула не User');
    }

    /**
     * Комментарий-предусловие: вставляется в БД, а не через форму гостя.
     *
     * @param array<string, mixed> $overrides
     */
    public function haveComment(array $overrides = []): Comment
    {
        $comment = $this->load(Comment::class, $this->fixtureFactory()->commentRow($overrides));

        return $comment instanceof Comment ? $comment : throw new RuntimeException('Фикстура comment вернула не Comment');
    }

    /**
     * Несколько комментариев с умолчаниями фабрики, по одному INSERT: у каждого следующего created_at позже.
     * Возвращает их тексты в порядке создания (тексты уникальны: в каждом своя метка).
     *
     * @param array<string, mixed> $overrides
     *
     * @return list<string>
     */
    public function haveComments(int $count, array $overrides = []): array
    {
        $texts = [];
        for ($i = 0; $i < $count; ++$i) {
            $texts[] = $this->haveComment($overrides)->text;
        }

        return $texts;
    }

    /**
     * Пустая таблица comment для тестов, которые считают все строки (страницы, итоги). Только внутри
     * транзакции unit-теста: после теста модуль Yii2 откатывает удаление.
     */
    public function haveNoComments(): void
    {
        $db = Yii::$app->db;
        $db->open();
        if ($db->getTransaction() === null) {
            throw new RuntimeException('haveNoComments работает только внутри транзакции модуля Yii2 (transaction: true)');
        }

        Comment::deleteAll();
    }

    /**
     * @param class-string<User|Comment> $modelClass
     * @param array<string, mixed> $row
     */
    private function load(string $modelClass, array $row): mixed
    {
        $name = sprintf('fixture-%d', ++$this->loaded);
        $yii = $this->getModule('Yii2');
        assert($yii instanceof Yii2);
        $yii->haveFixtures([$name => ['class' => GeneratedFixture::class, 'modelClass' => $modelClass, 'rows' => ['row' => $row]]]);

        return $yii->grabFixture($name, 'row');
    }
}
