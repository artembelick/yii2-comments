<?php

declare(strict_types=1);

namespace common\tests\unit\models;

use Codeception\Test\Unit;
use common\models\User;
use common\tests\_support\FixtureFactory;
use common\tests\UnitTester;
use PHPUnit\Framework\Attributes\TestDox;
use yii\base\NotSupportedException;

final class UserTest extends Unit
{
    private const int MISSING_ID = 2_000_000_000;

    protected UnitTester $tester;

    #[TestDox('UNIT-USER-01: findIdentity находит активного админа и не находит заблокированного или несуществующего')]
    public function testFindIdentityReturnsOnlyActiveAdmins(): void
    {
        $active = $this->tester->haveAdmin();
        $blocked = $this->tester->haveAdmin(['status' => User::STATUS_BLOCKED]);

        $found = [
            User::findIdentity($active->id)?->getId(),
            User::findIdentity($blocked->id),
            User::findIdentity(self::MISSING_ID),
        ];

        self::assertSame([$active->id, null, null], $found);
    }

    #[TestDox('UNIT-USER-02: findIdentityByAccessToken выбрасывает NotSupportedException')]
    public function testAccessTokensAreNotSupported(): void
    {
        $this->expectException(NotSupportedException::class);

        User::findIdentityByAccessToken('token');
    }

    #[TestDox('UNIT-USER-03: validatePassword — true для верного пароля и false для неверного')]
    public function testValidatePasswordChecksHash(): void
    {
        $admin = $this->tester->haveAdmin();

        $results = [$admin->validatePassword(FixtureFactory::ADMIN_PASSWORD), $admin->validatePassword('wrong-password')];

        self::assertSame([true, false], $results);
    }

    #[TestDox('UNIT-USER-04: validateAuthKey принимает только текущий ключ')]
    public function testValidateAuthKeyComparesKeys(): void
    {
        $admin = $this->tester->haveAdmin();

        $results = [$admin->validateAuthKey($admin->getAuthKey()), $admin->validateAuthKey('other-key')];

        self::assertSame([true, false], $results);
    }

    #[TestDox('UNIT-USER-05: isBlocked — true у заблокированного и false у активного')]
    public function testIsBlockedFollowsStatus(): void
    {
        $active = $this->tester->haveAdmin();
        $blocked = $this->tester->haveAdmin(['status' => User::STATUS_BLOCKED]);

        $flags = [$active->isBlocked(), $blocked->isBlocked()];

        self::assertSame([false, true], $flags);
    }
}
