<?php

declare(strict_types=1);

namespace console\controllers;

use Closure;
use common\events\EventPublishException;
use common\models\User;
use common\services\AdminAccountService;
use common\services\AdminNotFoundException;
use common\services\InvalidAdminDataException;
use LogicException;
use Override;
use Yii;
use yii\base\Module;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Учётные записи админов: create, block, unblock, password. Пароль читается из STDIN, чтобы не попасть
 * в историю shell и список процессов: `printf '%s\n' "$PASSWORD" | ./yii admin/create anna`.
 * При вводе с терминала пароль виден на экране, поэтому его передают через pipe, как в примере.
 * Коды выхода: 0 — успех, 1 — неверные данные, 2 — админа с таким логином нет, 3 — блокировка или смена пароля
 * сохранены, но WS-сервер о них не узнал (Redis недоступен).
 */
final class AdminController extends Controller
{
    /** Код выхода «админа с таким логином нет». */
    private const int EXIT_NOT_FOUND = 2;

    /** Код выхода «изменение сохранено, но WS-сервер о нём не узнал». */
    private const int EXIT_WS_NOT_NOTIFIED = 3;

    /** @var resource|null поток, из которого читается пароль; по умолчанию STDIN */
    public $input;

    /** @var resource|null по умолчанию STDOUT */
    public $output;

    /** @var resource|null по умолчанию STDERR */
    public $errorOutput;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        string $id,
        Module $module,
        private readonly AdminAccountService $accounts,
        array $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    #[Override]
    public function init(): void
    {
        parent::init();
        $this->input ??= STDIN;
        $this->output ??= STDOUT;
        $this->errorOutput ??= STDERR;
    }

    /**
     * Создаёт активного админа. Пароль — первая строка STDIN.
     */
    public function actionCreate(string $username): int
    {
        return $this->perform(fn(): User => $this->accounts->create($username, $this->readPassword()), 'cli.admin.created', $username);
    }

    /**
     * Блокирует админа: он не может войти, его HTTP-сессии и WS-соединения завершаются.
     */
    public function actionBlock(string $username): int
    {
        return $this->perform(fn() => $this->accounts->block($username), 'cli.admin.blocked', $username);
    }

    /**
     * Снимает блокировку: админ снова может войти.
     */
    public function actionUnblock(string $username): int
    {
        return $this->perform(fn() => $this->accounts->unblock($username), 'cli.admin.unblocked', $username);
    }

    /**
     * Меняет пароль админа, все его сессии и WS-соединения завершаются. Новый пароль — первая строка STDIN.
     */
    public function actionPassword(string $username): int
    {
        return $this->perform(fn() => $this->accounts->changePassword($username, $this->readPassword()), 'cli.admin.password_changed', $username);
    }

    /**
     * Выполняет операцию и печатает итог: подтверждение в stdout или ошибки в stderr по одной на строку.
     *
     * @param Closure(): mixed $operation
     */
    private function perform(Closure $operation, string $confirmationKey, string $username): int
    {
        try {
            $operation();
        } catch (InvalidAdminDataException $invalidAdminDataException) {
            return $this->fail($invalidAdminDataException->errors, ExitCode::UNSPECIFIED_ERROR);
        } catch (AdminNotFoundException $adminNotFoundException) {
            return $this->fail([$adminNotFoundException->getMessage()], self::EXIT_NOT_FOUND);
        } catch (EventPublishException $eventPublishException) {
            Yii::error(sprintf('Отзыв доступа админа %s не опубликован: %s', $username, $eventPublishException->getMessage()), self::class);

            return $this->fail([Yii::t('app', 'cli.admin.ws_not_notified', ['username' => $username])], self::EXIT_WS_NOT_NOTIFIED);
        }

        fwrite($this->stream($this->output), Yii::t('app', $confirmationKey, ['username' => $username]) . "\n");

        return ExitCode::OK;
    }

    /**
     * @param list<string> $errors
     */
    private function fail(array $errors, int $exitCode): int
    {
        fwrite($this->stream($this->errorOutput), implode("\n", $errors) . "\n");

        return $exitCode;
    }

    private function readPassword(): string
    {
        return rtrim((string) fgets($this->stream($this->input)), "\r\n");
    }

    /**
     * @param resource|null $stream
     *
     * @return resource
     *
     * @throws LogicException поток не открыт: init() не вызван или поток подменили не ресурсом
     */
    private function stream($stream)
    {
        if (!is_resource($stream)) {
            throw new LogicException('Поток команды не открыт.');
        }

        return $stream;
    }
}
