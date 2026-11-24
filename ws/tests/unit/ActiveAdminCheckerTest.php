<?php

declare(strict_types=1);

namespace ws\tests\unit;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\models\User;
use common\repositories\UserRepositoryInterface;
use PHPUnit\Framework\Attributes\TestDox;
use ws\ActiveAdminChecker;
use ws\AdminCheckUnavailableException;
use ws\tests\_support\Outcomes;
use yii\db\Connection;
use yii\db\Exception;

final class ActiveAdminCheckerTest extends Unit
{
    private const int ADMIN_ID = 7;

    private const string AUTH_KEY = 'auth-key-of-anna';

    #[TestDox('UNIT-CHK-01: активный админ → true, заблокированный или несуществующий → false')]
    #[DataProvider('admins')]
    public function testChecksAdminIsActive(?User $found, bool $expected): void
    {
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findActiveById')->willReturnMap([[self::ADMIN_ID, $found]]);
        $checker = new ActiveAdminChecker($users, self::createStub(Connection::class));

        $active = $checker->hasValidAccess(self::ADMIN_ID, self::AUTH_KEY);

        self::assertSame($expected, $active);
    }

    /**
     * Заблокированного и несуществующего репозиторий одинаково не находит: findActiveById отдаёт только активных.
     *
     * @return array<string, array{?User, bool}>
     */
    public static function admins(): array
    {
        return [
            'активный' => [new User(['id' => self::ADMIN_ID, 'status' => User::STATUS_ACTIVE, 'auth_key' => self::AUTH_KEY]), true],
            'заблокированный или несуществующий' => [null, false],
        ];
    }

    #[TestDox('UNIT-CHK-04: auth_key активного админа не тот, что в токене (пароль сменили после выдачи) → false')]
    public function testChangedAuthKeyIsNotActive(): void
    {
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findActiveById')->willReturn(new User(['id' => self::ADMIN_ID, 'status' => User::STATUS_ACTIVE, 'auth_key' => 'auth-key-after-password-change']));
        $checker = new ActiveAdminChecker($users, self::createStub(Connection::class));

        $active = $checker->hasValidAccess(self::ADMIN_ID, self::AUTH_KEY);

        self::assertFalse($active);
    }

    #[TestDox('UNIT-CHK-02: ошибка соединения с БД → переподключение и один повтор, повтор успешен → результат')]
    public function testReconnectsOnceAfterConnectionError(): void
    {
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findActiveById')->willReturnCallback(Outcomes::inOrder(
            new Exception('server closed the connection unexpectedly'),
            new User(['id' => self::ADMIN_ID, 'status' => User::STATUS_ACTIVE, 'auth_key' => self::AUTH_KEY]),
        ));
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('close');

        $active = (new ActiveAdminChecker($users, $db))->hasValidAccess(self::ADMIN_ID, self::AUTH_KEY);

        self::assertTrue($active);
    }

    #[TestDox('UNIT-CHK-03: повтор после переподключения тоже упал → AdminCheckUnavailableException')]
    public function testFailsWhenRetryFails(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::exactly(2))->method('findActiveById')->willThrowException(new Exception('server closed the connection unexpectedly'));
        $checker = new ActiveAdminChecker($users, self::createStub(Connection::class));

        $this->expectException(AdminCheckUnavailableException::class);

        $checker->hasValidAccess(self::ADMIN_ID, self::AUTH_KEY);
    }
}
