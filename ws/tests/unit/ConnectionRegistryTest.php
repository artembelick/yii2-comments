<?php

declare(strict_types=1);

namespace ws\tests\unit;

use Codeception\Test\Unit;
use PHPUnit\Framework\Attributes\TestDox;
use ws\ConnectionRegistry;
use ws\tests\_support\FakeConnection;

final class ConnectionRegistryTest extends Unit
{
    private const int ANNA = 7;

    private const int BORIS = 8;

    #[TestDox('UNIT-REG-01: добавленное соединение не авторизовано и не попадает в authenticated()')]
    public function testAddedConnectionIsNotAuthenticated(): void
    {
        $registry = new ConnectionRegistry();
        $connection = new FakeConnection('1');

        $registry->add($connection);

        self::assertSame([false, []], [$registry->isAuthenticated($connection), $registry->authenticated()]);
    }

    #[TestDox('UNIT-REG-02: после authenticate соединение находится через authenticated, bySession и byUser')]
    public function testAuthenticatedConnectionIsFound(): void
    {
        $registry = new ConnectionRegistry();
        $connection = new FakeConnection('1');
        $registry->add($connection);

        $registry->authenticate($connection, self::ANNA, 'session-1');

        self::assertTrue($registry->isAuthenticated($connection));
        self::assertSame(
            [[$connection], [$connection], [$connection]],
            [$registry->authenticated(), $registry->bySession('session-1'), $registry->byUser(self::ANNA)],
        );
    }

    #[TestDox('UNIT-REG-03: remove убирает соединение из всех выборок')]
    public function testRemovedConnectionIsGone(): void
    {
        $registry = new ConnectionRegistry();
        $connection = new FakeConnection('1');
        $registry->add($connection);
        $registry->authenticate($connection, self::ANNA, 'session-1');

        $registry->remove($connection);

        self::assertFalse($registry->isAuthenticated($connection));
        self::assertSame(
            [[], [], []],
            [$registry->authenticated(), $registry->bySession('session-1'), $registry->byUser(self::ANNA)],
        );
    }

    #[TestDox('UNIT-REG-03: повторный remove не падает')]
    public function testSecondRemoveIsHarmless(): void
    {
        $registry = new ConnectionRegistry();
        $connection = new FakeConnection('1');
        $registry->add($connection);
        $registry->remove($connection);

        $registry->remove($connection);

        self::assertSame([], $registry->authenticated());
    }

    #[TestDox('UNIT-REG-04: все соединения одной сессии и одного админа находятся, чужие не попадают')]
    public function testSelectionsKeepOwnConnectionsOnly(): void
    {
        $registry = new ConnectionRegistry();
        $annaTab1 = new FakeConnection('1');
        $annaTab2 = new FakeConnection('2');
        $annaOtherSession = new FakeConnection('3');
        $boris = new FakeConnection('4');
        $unauthenticated = new FakeConnection('5');
        $registry->add($annaTab1);
        $registry->add($annaTab2);
        $registry->add($annaOtherSession);
        $registry->add($boris);
        $registry->add($unauthenticated);

        $registry->authenticate($annaTab1, self::ANNA, 'session-1');
        $registry->authenticate($annaTab2, self::ANNA, 'session-1');
        $registry->authenticate($annaOtherSession, self::ANNA, 'session-2');
        $registry->authenticate($boris, self::BORIS, 'session-3');

        self::assertSame(
            [[$annaTab1, $annaTab2], [$annaTab1, $annaTab2, $annaOtherSession], [$annaTab1, $annaTab2, $annaOtherSession, $boris]],
            [$registry->bySession('session-1'), $registry->byUser(self::ANNA), $registry->authenticated()],
        );
    }
}
