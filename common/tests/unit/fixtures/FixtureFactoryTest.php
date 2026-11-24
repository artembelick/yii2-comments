<?php

declare(strict_types=1);

namespace common\tests\unit\fixtures;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\tests\_support\FixtureFactory;
use common\tests\UnitTester;
use Faker\Factory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestDox;
use Yii;

final class FixtureFactoryTest extends Unit
{
    protected UnitTester $tester;

    #[TestDox('UNIT-FIX-01: генераторы фикстур заполняют ровно колонки таблиц user и comment')]
    public function testGeneratorsFillEveryColumnOfTheSchema(): void
    {
        $factory = $this->tester->fixtureFactory();
        $filled = [
            'user' => array_keys($factory->adminRow()),
            'comment' => array_keys($factory->commentRow()),
        ];
        sort($filled['user']);
        sort($filled['comment']);

        $columns = [
            'user' => $this->columnsToFill('user'),
            'comment' => $this->columnsToFill('comment'),
        ];

        self::assertSame($columns, $filled);
    }

    #[TestDox('UNIT-FIX-02: два вызова генератора дают разные метки и вставляются без конфликта')]
    public function testTwoGeneratedAdminsGetDifferentTags(): void
    {
        $first = $this->tester->haveAdmin();

        $second = $this->tester->haveAdmin();

        self::assertNotSame($first->username, $second->username);
        self::assertNotSame($first->id, $second->id);
    }

    #[TestDox('UNIT-FIX-02: при одном seed и разных TEST_RUN_ID метки разные')]
    public function testSameSeedInDifferentRunsGivesDifferentTags(): void
    {
        $tags = [$this->firstTag(seed: 42, runId: 'k3x9'), $this->firstTag(seed: 42, runId: 'p7q2')];

        self::assertNotSame($tags[0], $tags[1]);
    }

    #[TestDox('UNIT-FIX-02: при одном seed и одном TEST_RUN_ID метки одинаковые')]
    public function testSameSeedInSameRunGivesSameTag(): void
    {
        $tags = [$this->firstTag(seed: 42, runId: 'k3x9'), $this->firstTag(seed: 42, runId: 'k3x9')];

        self::assertSame($tags[0], $tags[1]);
    }

    #[TestDox('UNIT-FIX-03: метка — TEST_RUN_ID и 4 символа [a-z0-9] из Faker')]
    public function testTagIsRunIdFollowedByFakerPart(): void
    {
        $factory = $this->factory(seed: 42, runId: 'k3x9');

        $tag = $factory->tag();

        self::assertMatchesRegularExpression('/^k3x9[a-z0-9]{4}$/', $tag);
    }

    #[TestDox('UNIT-FIX-03: без годного TEST_RUN_ID генератор не создаётся, сообщение называет TEST_RUN_ID')]
    #[DataProvider('invalidRunIds')]
    public function testInvalidRunIdIsRejected(string $runId): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/TEST_RUN_ID/');

        $this->factory(seed: 42, runId: $runId);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidRunIds(): array
    {
        return [
            'пустой (переменная не задана)' => [''],
            '3 символа' => ['k3x'],
            '5 символов' => ['k3x9a'],
            'заглавные буквы' => ['K3X9'],
            'дефис' => ['k3-9'],
        ];
    }

    /** Первая метка генератора. Faker сеет общий mt_rand, поэтому метка берётся сразу после seed. */
    private function firstTag(int $seed, string $runId): string
    {
        return $this->factory($seed, $runId)->tag();
    }

    private function factory(int $seed, string $runId): FixtureFactory
    {
        $faker = Factory::create('ru_RU');
        $faker->seed($seed);

        return new FixtureFactory($faker, $runId);
    }

    /**
     * @return list<string>
     */
    private function columnsToFill(string $table): array
    {
        /** @var list<string> */
        return Yii::$app->db->createCommand(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = :table AND column_name <> 'id'
             ORDER BY column_name COLLATE \"C\"",
            [':table' => $table],
        )->queryColumn();
    }
}
