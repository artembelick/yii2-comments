<?php

declare(strict_types=1);

namespace common\tests\unit\services;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\models\User;
use common\repositories\UserRepositoryInterface;
use common\services\AdminAccountService;
use common\services\InvalidAdminDataException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Clock\MockClock;

final class AdminAccountServiceTest extends Unit
{
    private const string VALID_PASSWORD = 'twelve-chars';

    private const string NOW = '2026-11-24 10:00:00+00:00';

    #[TestDox('UNIT-ACC-01: логин из 3–64 символов [A-Za-z0-9_.-] принимается')]
    #[DataProvider('validUsernames')]
    public function testValidUsernameIsAccepted(string $username): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('create')->with($username)->willReturn(new User());

        (new AdminAccountService($users, new MockClock(self::NOW)))->create($username, self::VALID_PASSWORD);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validUsernames(): array
    {
        return [
            '3 символа' => ['a_b'],
            '64 символа' => [str_repeat('a.-', 21) . 'z'],
        ];
    }

    #[TestDox('UNIT-ACC-01: логин короче 3, длиннее 64, с пробелом или кириллицей отклоняется')]
    #[DataProvider('invalidUsernames')]
    public function testInvalidUsernameIsRejected(string $username): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::never())->method('create');

        $this->expectException(InvalidAdminDataException::class);

        (new AdminAccountService($users, new MockClock(self::NOW)))->create($username, self::VALID_PASSWORD);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUsernames(): array
    {
        return [
            '2 символа' => ['ab'],
            '65 символов' => [str_repeat('a', 65)],
            'пробел' => ['anna smith'],
            'кириллица' => ['анна'],
        ];
    }

    #[TestDox('UNIT-ACC-02: пароль из 11 символов отклоняется')]
    public function testShortPasswordIsRejected(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::never())->method('create');

        $this->expectException(InvalidAdminDataException::class);

        (new AdminAccountService($users, new MockClock(self::NOW)))->create('anna', 'eleven-char');
    }

    #[TestDox('UNIT-ACC-02: пароль из 12 символов принимается')]
    public function testTwelveCharPasswordIsAccepted(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('create')->willReturn(new User());

        (new AdminAccountService($users, new MockClock(self::NOW)))->create('anna', self::VALID_PASSWORD);
    }

    #[TestDox('UNIT-ACC-03: create занятого логина в любом регистре отклоняется')]
    public function testTakenUsernameIsRejected(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findByUsername')->with('ANNA')->willReturn(new User());
        $users->expects(self::never())->method('create');

        $this->expectException(InvalidAdminDataException::class);

        (new AdminAccountService($users, new MockClock(self::NOW)))->create('ANNA', self::VALID_PASSWORD);
    }

    #[TestDox('UNIT-ACC-04: create хэширует пароль, генерирует auth_key и создаёт админа со временем из часов')]
    public function testCreateStoresHashAndAuthKey(): void
    {
        $created = new User();
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('create')->with(
            'anna',
            self::callback(static fn(string $hash): bool => password_verify(self::VALID_PASSWORD, $hash)),
            self::matchesRegularExpression('/^[A-Za-z0-9_-]{32}$/'),
            self::equalTo(new DateTimeImmutable(self::NOW)),
        )->willReturn($created);

        $admin = (new AdminAccountService($users, new MockClock(self::NOW)))->create('anna', self::VALID_PASSWORD);

        self::assertSame($created, $admin);
    }
}
