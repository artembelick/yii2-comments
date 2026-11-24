<?php

declare(strict_types=1);

namespace common\tests\unit\repositories;

use Codeception\Test\Unit;
use common\models\User;
use common\repositories\ActiveRecordUserRepository;
use common\tests\UnitTester;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestDox;
use yii\db\IntegrityException;

final class ActiveRecordUserRepositoryTest extends Unit
{
    protected UnitTester $tester;

    #[TestDox('UNIT-UREPO-01: findByUsername ищет без учёта регистра')]
    public function testFindByUsernameIgnoresCase(): void
    {
        $anna = $this->tester->haveAdmin(['username' => 'anna-' . $this->tester->fixtureFactory()->tag()]);
        $repository = new ActiveRecordUserRepository();

        $found = $repository->findByUsername(strtoupper($anna->username));

        self::assertSame($anna->id, $found?->id);
    }

    #[TestDox('UNIT-UREPO-02: create логина, отличного от существующего только регистром, падает на уникальном индексе')]
    public function testCreateRejectsUsernameDifferingOnlyInCase(): void
    {
        $anna = $this->tester->haveAdmin(['username' => 'anna-' . $this->tester->fixtureFactory()->tag()]);
        $repository = new ActiveRecordUserRepository();

        $this->expectException(IntegrityException::class);

        $repository->create(strtoupper($anna->username), 'hash', 'auth-key', new DateTimeImmutable('2026-11-24 10:00:00+00:00'));
    }

    #[TestDox('UNIT-UREPO-03: findActiveById не находит заблокированного')]
    public function testFindActiveByIdSkipsBlocked(): void
    {
        $active = $this->tester->haveAdmin();
        $blocked = $this->tester->haveAdmin(['status' => User::STATUS_BLOCKED]);
        $repository = new ActiveRecordUserRepository();

        $found = [$repository->findActiveById($active->id)?->id, $repository->findActiveById($blocked->id)];

        self::assertSame([$active->id, null], $found);
    }

    #[TestDox('UNIT-UREPO-05: create сохраняет активного админа с логином, хэшем, auth_key и временем')]
    public function testCreateStoresActiveAdmin(): void
    {
        $username = 'anna-' . $this->tester->fixtureFactory()->tag();
        $repository = new ActiveRecordUserRepository();

        $created = $repository->create($username, 'password-hash', 'auth-key', new DateTimeImmutable('2026-11-24 10:00:00+00:00'));

        $stored = $repository->findActiveById($created->id);
        self::assertSame(
            [$username, 'password-hash', 'auth-key', User::STATUS_ACTIVE, '2026-11-24 10:00:00+00', '2026-11-24 10:00:00+00'],
            [$stored?->username, $stored?->password_hash, $stored?->auth_key, $stored?->status, $stored?->created_at, $stored?->updated_at],
        );
    }
}
