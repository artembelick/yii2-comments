<?php

declare(strict_types=1);

namespace common\tests\unit\fixtures;

use Codeception\Test\Unit;
use common\tests\UnitTester;
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
