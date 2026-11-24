<?php

declare(strict_types=1);

namespace console\controllers;

use common\services\AdminAccountService;
use common\services\InvalidAdminDataException;
use LogicException;
use Override;
use Yii;
use yii\base\Module;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Учётные записи админов. Пароль читается из STDIN, чтобы не попасть в историю shell и список процессов:
 * `printf '%s\n' "$PASSWORD" | ./yii admin/create anna`.
 */
final class AdminController extends Controller
{
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
        try {
            $this->accounts->create($username, $this->readPassword());
        } catch (InvalidAdminDataException $invalidAdminDataException) {
            fwrite($this->stream($this->errorOutput), implode("\n", $invalidAdminDataException->errors) . "\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        fwrite($this->stream($this->output), Yii::t('app', 'cli.admin.created', ['username' => $username]) . "\n");

        return ExitCode::OK;
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
