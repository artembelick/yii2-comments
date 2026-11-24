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

    #[TestDox('UNIT-UREPO-04: setStatus меняет статус, auth_key и updated_at, хэш пароля не трогает')]
    public function testSetStatusChangesStatusAuthKeyAndUpdatedAt(): void
    {
        $anna = $this->tester->haveAdmin();
        $repository = new ActiveRecordUserRepository();

        $repository->setStatus($anna->id, User::STATUS_BLOCKED, 'new-auth-key', new DateTimeImmutable('2026-11-24 10:00:00+00:00'));

        $stored = $repository->findByUsername($anna->username);
        self::assertSame(
            [User::STATUS_BLOCKED, 'new-auth-key', '2026-11-24 10:00:00+00', $anna->password_hash],
            [$stored?->status, $stored?->auth_key, $stored?->updated_at, $stored?->password_hash],
        );
    }

    #[TestDox('UNIT-UREPO-04: setPassword меняет хэш пароля, auth_key и updated_at, статус не трогает')]
    public function testSetPasswordChangesHashAuthKeyAndUpdatedAt(): void
    {
        $anna = $this->tester->haveAdmin();
        $repository = new ActiveRecordUserRepository();

        $repository->setPassword($anna->id, 'new-password-hash', 'new-auth-key', new DateTimeImmutable('2026-11-24 10:00:00+00:00'));

        $stored = $repository->findByUsername($anna->username);
        self::assertSame(
            ['new-password-hash', 'new-auth-key', '2026-11-24 10:00:00+00', User::STATUS_ACTIVE],
            [$stored?->password_hash, $stored?->auth_key, $stored?->updated_at, $stored?->status],
        );
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
