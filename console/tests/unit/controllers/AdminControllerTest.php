<?php

declare(strict_types=1);

namespace console\tests\unit\controllers;

use Codeception\Test\Unit;
use common\models\User;
use common\repositories\UserRepositoryInterface;
use common\services\AdminAccountService;
use console\controllers\AdminController;
use LogicException;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Clock\MockClock;
use Yii;
use yii\console\ExitCode;

final class AdminControllerTest extends Unit
{
    private const string PASSWORD = 'correct-horse-battery';

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
    private function controller(UserRepositoryInterface $users, array $io): AdminController
    {
        return new AdminController('admin', Yii::$app, new AdminAccountService($users, new MockClock('2026-11-24 10:00:00+00:00')), $io);
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
