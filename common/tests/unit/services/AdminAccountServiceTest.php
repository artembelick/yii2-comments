<?php

declare(strict_types=1);

namespace common\tests\unit\services;

use Closure;
use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\events\EventPublisherInterface;
use common\models\User;
use common\repositories\UserRepositoryInterface;
use common\services\AdminAccountService;
use common\services\AdminNotFoundException;
use common\services\InvalidAdminDataException;
use common\tests\_support\AdminAccountDoubles;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Constraint\LogicalAnd;
use Symfony\Component\Clock\MockClock;

final class AdminAccountServiceTest extends Unit
{
    use AdminAccountDoubles;

    private const string VALID_PASSWORD = 'twelve-chars';

    private const string NOW = '2026-11-24 10:00:00+00:00';

    #[TestDox('UNIT-ACC-01: логин из 3–64 символов [A-Za-z0-9_.-] принимается')]
    #[DataProvider('validUsernames')]
    public function testValidUsernameIsAccepted(string $username): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('create')->with($username)->willReturn(new User());

        $this->service($users)->create($username, self::VALID_PASSWORD);
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

        $this->service($users)->create($username, self::VALID_PASSWORD);
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

        $this->service($users)->create('anna', 'eleven-char');
    }

    #[TestDox('UNIT-ACC-02: пароль из 12 символов принимается')]
    public function testTwelveCharPasswordIsAccepted(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('create')->willReturn(new User());

        $this->service($users)->create('anna', self::VALID_PASSWORD);
    }

    #[TestDox('UNIT-ACC-03: create занятого логина в любом регистре отклоняется')]
    public function testTakenUsernameIsRejected(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findByUsername')->with('ANNA')->willReturn(new User());
        $users->expects(self::never())->method('create');

        $this->expectException(InvalidAdminDataException::class);

        $this->service($users)->create('ANNA', self::VALID_PASSWORD);
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

        $admin = $this->service($users)->create('anna', self::VALID_PASSWORD);

        self::assertSame($created, $admin);
    }

    #[TestDox('UNIT-ACC-05: block ставит статус «заблокирован» и новый auth_key со временем из часов: старые сессии не оживут после unblock')]
    public function testBlockSetsBlockedStatusAndNewAuthKey(): void
    {
        $users = $this->repositoryWith($this->anna());
        $users->expects(self::once())->method('setStatus')->with(self::ANNA_ID, User::STATUS_BLOCKED, $this->newAuthKey(), self::equalTo(new DateTimeImmutable(self::NOW)));

        $this->service($users)->block('anna');
    }

    #[TestDox('UNIT-ACC-05: block после смены статуса вызывает accessRevoked: WS закрывает все соединения админа')]
    public function testBlockRevokesAccessAfterStatusChange(): void
    {
        $calls = [];
        $users = $this->repositoryWith($this->anna());
        $users->method('setStatus')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'setStatus';
        });
        $events = self::createStub(EventPublisherInterface::class);
        $events->method('publish')->willReturnCallback(static function (string $channel, array $message) use (&$calls): void {
            $calls[] = [$channel, $message];
        });

        $this->service($users, $events)->block('anna');

        self::assertSame(['setStatus', ['ws.control', ['type' => 'access.revoked', 'userId' => self::ANNA_ID]]], $calls);
    }

    #[TestDox('UNIT-ACC-06: unblock ставит статус «активен» и новый auth_key и accessRevoked не вызывает')]
    public function testUnblockSetsActiveStatusWithoutRevoking(): void
    {
        $users = $this->repositoryWith($this->anna());
        $users->expects(self::once())->method('setStatus')->with(self::ANNA_ID, User::STATUS_ACTIVE, $this->newAuthKey(), self::equalTo(new DateTimeImmutable(self::NOW)));
        $events = $this->createMock(EventPublisherInterface::class);
        $events->expects(self::never())->method('publish');

        $this->service($users, $events)->unblock('anna');
    }

    #[TestDox('UNIT-ACC-07: changePassword сохраняет хэш нового пароля и новый auth_key со временем из часов')]
    public function testChangePasswordStoresHashAndNewAuthKey(): void
    {
        $users = $this->repositoryWith($this->anna());
        $users->expects(self::once())->method('setPassword')->with(
            self::ANNA_ID,
            self::callback(static fn(string $hash): bool => password_verify(self::VALID_PASSWORD, $hash)),
            $this->newAuthKey(),
            self::equalTo(new DateTimeImmutable(self::NOW)),
        );

        $this->service($users)->changePassword('anna', self::VALID_PASSWORD);
    }

    #[TestDox('UNIT-ACC-07: changePassword после смены пароля вызывает accessRevoked: WS закрывает все соединения админа')]
    public function testChangePasswordRevokesAccessAfterChange(): void
    {
        $calls = [];
        $users = $this->repositoryWith($this->anna());
        $users->method('setPassword')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'setPassword';
        });
        $events = self::createStub(EventPublisherInterface::class);
        $events->method('publish')->willReturnCallback(static function (string $channel, array $message) use (&$calls): void {
            $calls[] = [$channel, $message];
        });

        $this->service($users, $events)->changePassword('anna', self::VALID_PASSWORD);

        self::assertSame(['setPassword', ['ws.control', ['type' => 'access.revoked', 'userId' => self::ANNA_ID]]], $calls);
    }

    #[TestDox('UNIT-ACC-07: changePassword с паролем короче 12 символов → InvalidAdminDataException, ничего не меняется')]
    public function testChangePasswordRejectsShortPassword(): void
    {
        $users = $this->repositoryWith($this->anna());
        $users->expects(self::never())->method('setPassword');

        $this->expectException(InvalidAdminDataException::class);

        $this->service($users)->changePassword('anna', 'eleven-char');
    }

    /**
     * @param Closure(AdminAccountService): void $operation
     */
    #[TestDox('UNIT-ACC-08: операция с несуществующим логином → AdminNotFoundException')]
    #[DataProvider('operations')]
    public function testUnknownUsernameIsNotFound(Closure $operation): void
    {
        $users = $this->repositoryWith(null);
        $users->expects(self::never())->method('setStatus');
        $users->expects(self::never())->method('setPassword');

        $this->expectException(AdminNotFoundException::class);

        $operation($this->service($users));
    }

    /**
     * @return array<string, array{Closure(AdminAccountService): void}>
     */
    public static function operations(): array
    {
        return [
            'block' => [static fn(AdminAccountService $accounts) => $accounts->block('ghost')],
            'unblock' => [static fn(AdminAccountService $accounts) => $accounts->unblock('ghost')],
            'changePassword' => [static fn(AdminAccountService $accounts) => $accounts->changePassword('ghost', self::VALID_PASSWORD)],
        ];
    }

    /** Сгенерированный auth_key: 32 символа и не прежний ключ Анны. */
    private function newAuthKey(): LogicalAnd
    {
        return self::logicalAnd(self::matchesRegularExpression('/^[A-Za-z0-9_-]{32}$/'), self::logicalNot(self::identicalTo(self::OLD_AUTH_KEY)));
    }

    /** Сервис с часами на NOW. */
    private function service(UserRepositoryInterface $users, ?EventPublisherInterface $events = null): AdminAccountService
    {
        return new AdminAccountService($users, new MockClock(self::NOW), $this->sessionTerminator($events));
    }
}
