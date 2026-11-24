<?php

declare(strict_types=1);

namespace console\tests\unit\controllers;

use Closure;
use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\events\EventPublisherInterface;
use common\events\EventPublishException;
use common\models\User;
use common\repositories\UserRepositoryInterface;
use common\services\AdminAccountService;
use common\tests\_support\AdminAccountDoubles;
use console\controllers\AdminController;
use LogicException;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Clock\MockClock;
use Yii;
use yii\console\ExitCode;

final class AdminControllerTest extends Unit
{
    use AdminAccountDoubles;

    private const string PASSWORD = 'correct-horse-battery';

    /** Код выхода «админа с таким логином нет». */
    private const int EXIT_NOT_FOUND = 2;

    /** Код выхода «изменение сохранено, но WS-сервер о нём не узнал». */
    private const int EXIT_WS_NOT_NOTIFIED = 3;

    #[TestDox('UNIT-CLI-01: admin/create — код 0 и подтверждение в stdout')]
    public function testCreateConfirmsSuccess(): void
    {
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('create')->willReturn(new User());
        $io = $this->io(self::PASSWORD . "\n");

        $code = $this->controller($users, $io)->actionCreate('anna');

        self::assertSame([ExitCode::OK, Yii::t('app', 'cli.admin.created', ['username' => 'anna']) . "\n", ''], [$code, $this->read($io['output']), $this->read($io['errorOutput'])]);
    }

    #[TestDox('UNIT-CLI-02: admin/create с неверными данными — код 1 и ошибки в stderr')]
    public function testCreateReportsInvalidData(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::never())->method('create');
        $io = $this->io("short\n");

        $code = $this->controller($users, $io)->actionCreate('a b');

        self::assertSame(
            [
                ExitCode::UNSPECIFIED_ERROR,
                '',
                Yii::t('app', 'cli.admin.username_invalid') . "\n" . Yii::t('app', 'cli.admin.password_too_short', ['min' => 12]) . "\n",
            ],
            [$code, $this->read($io['output']), $this->read($io['errorOutput'])],
        );
    }

    #[TestDox('UNIT-CLI-04: admin/create читает пароль из STDIN и не печатает его')]
    public function testCreateReadsPasswordFromStdinAndNeverPrintsIt(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('create')->with(
            'anna',
            self::callback(static fn(string $hash): bool => password_verify(self::PASSWORD, $hash)),
        )->willReturn(new User());
        $io = $this->io(self::PASSWORD . "\n");

        $this->controller($users, $io)->actionCreate('anna');

        self::assertStringNotContainsString(self::PASSWORD, $this->read($io['output']) . $this->read($io['errorOutput']));
    }

    #[TestDox('UNIT-CLI-05: закрытый поток вывода → LogicException, а не молчаливый сбой')]
    public function testClosedOutputStreamFailsLoudly(): void
    {
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('create')->willReturn(new User());
        $io = $this->io(self::PASSWORD . "\n");
        fclose($io['output']);

        $this->expectException(LogicException::class);

        $this->controller($users, $io)->actionCreate('anna');
    }

    /**
     * @param Closure(AdminController): int $command
     */
    #[TestDox('UNIT-CLI-01: admin/block, admin/unblock, admin/password — код 0 и подтверждение в stdout')]
    #[DataProvider('commandsWithConfirmation')]
    public function testAccountCommandConfirmsSuccess(Closure $command, string $confirmationKey): void
    {
        $io = $this->io(self::PASSWORD . "\n");

        $code = $command($this->controller($this->repositoryWith($this->anna()), $io));

        self::assertSame([ExitCode::OK, Yii::t('app', $confirmationKey, ['username' => 'anna']) . "\n", ''], [$code, $this->read($io['output']), $this->read($io['errorOutput'])]);
    }

    /**
     * @return array<string, array{Closure(AdminController): int, string}>
     */
    public static function commandsWithConfirmation(): array
    {
        return [
            'admin/block' => [static fn(AdminController $admin): int => $admin->actionBlock('anna'), 'cli.admin.blocked'],
            'admin/unblock' => [static fn(AdminController $admin): int => $admin->actionUnblock('anna'), 'cli.admin.unblocked'],
            'admin/password' => [static fn(AdminController $admin): int => $admin->actionPassword('anna'), 'cli.admin.password_changed'],
        ];
    }

    #[TestDox('UNIT-CLI-02: admin/password с коротким паролем — код 1 и ошибка в stderr')]
    public function testPasswordReportsInvalidData(): void
    {
        $users = $this->repositoryWith($this->anna());
        $users->expects(self::never())->method('setPassword');
        $io = $this->io("short\n");

        $code = $this->controller($users, $io)->actionPassword('anna');

        self::assertSame(
            [ExitCode::UNSPECIFIED_ERROR, '', Yii::t('app', 'cli.admin.password_too_short', ['min' => 12]) . "\n"],
            [$code, $this->read($io['output']), $this->read($io['errorOutput'])],
        );
    }

    /**
     * @param Closure(AdminController): int $command
     */
    #[TestDox('UNIT-CLI-03: админа с таким логином нет — код 2 и сообщение в stderr')]
    #[DataProvider('accountCommands')]
    public function testUnknownAdminExitsWithCodeTwo(Closure $command): void
    {
        $io = $this->io(self::PASSWORD . "\n");

        $code = $command($this->controller($this->repositoryWith(null), $io));

        self::assertSame([self::EXIT_NOT_FOUND, '', Yii::t('app', 'cli.admin.not_found', ['username' => 'ghost']) . "\n"], [$code, $this->read($io['output']), $this->read($io['errorOutput'])]);
    }

    /**
     * @return array<string, array{Closure(AdminController): int}>
     */
    public static function accountCommands(): array
    {
        return [
            'admin/block' => [static fn(AdminController $admin): int => $admin->actionBlock('ghost')],
            'admin/unblock' => [static fn(AdminController $admin): int => $admin->actionUnblock('ghost')],
            'admin/password' => [static fn(AdminController $admin): int => $admin->actionPassword('ghost')],
        ];
    }

    /**
     * @param Closure(AdminController): int $command
     */
    #[TestDox('UNIT-CLI-06: admin/block и admin/password при недоступном Redis — код 3 и предупреждение в stderr, а не молчаливый успех')]
    #[DataProvider('revokingCommands')]
    public function testUnnotifiedRevocationIsReported(Closure $command): void
    {
        $events = self::createStub(EventPublisherInterface::class);
        $events->method('publish')->willThrowException(new EventPublishException('Событие в ws.control не опубликовано'));
        $io = $this->io(self::PASSWORD . "\n");

        $code = $command($this->controller($this->repositoryWith($this->anna()), $io, $events));

        self::assertSame(
            [self::EXIT_WS_NOT_NOTIFIED, '', Yii::t('app', 'cli.admin.ws_not_notified', ['username' => 'anna']) . "\n"],
            [$code, $this->read($io['output']), $this->read($io['errorOutput'])],
        );
    }

    /**
     * @return array<string, array{Closure(AdminController): int}>
     */
    public static function revokingCommands(): array
    {
        return [
            'admin/block' => [static fn(AdminController $admin): int => $admin->actionBlock('anna')],
            'admin/password' => [static fn(AdminController $admin): int => $admin->actionPassword('anna')],
        ];
    }

    #[TestDox('UNIT-CLI-04: admin/password читает пароль из STDIN и не печатает его')]
    public function testPasswordReadsPasswordFromStdinAndNeverPrintsIt(): void
    {
        $users = $this->repositoryWith($this->anna());
        $users->expects(self::once())->method('setPassword')->with(
            self::ANNA_ID,
            self::callback(static fn(string $hash): bool => password_verify(self::PASSWORD, $hash)),
        );
        $io = $this->io(self::PASSWORD . "\n");

        $this->controller($users, $io)->actionPassword('anna');

        self::assertStringNotContainsString(self::PASSWORD, $this->read($io['output']) . $this->read($io['errorOutput']));
    }

    /**
     * Потоки консоли в памяти: stdin с введённым паролем, stdout и stderr для проверки вывода.
     *
     * @return array{input: resource, output: resource, errorOutput: resource}
     */
    private function io(string $stdin): array
    {
        $streams = ['input' => $this->memoryStream(), 'output' => $this->memoryStream(), 'errorOutput' => $this->memoryStream()];
        fwrite($streams['input'], $stdin);
        rewind($streams['input']);

        return $streams;
    }

    /**
     * @return resource
     */
    private function memoryStream()
    {
        $stream = fopen('php://memory', 'w+b');
        assert(is_resource($stream));

        return $stream;
    }

    /**
     * @param array{input: resource, output: resource, errorOutput: resource} $io
     */
    private function controller(UserRepositoryInterface $users, array $io, ?EventPublisherInterface $events = null): AdminController
    {
        $accounts = new AdminAccountService($users, new MockClock('2026-11-24 10:00:00+00:00'), $this->sessionTerminator($events));

        return new AdminController('admin', Yii::$app, $accounts, $io);
    }

    /**
     * @param resource $stream
     */
    private function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
