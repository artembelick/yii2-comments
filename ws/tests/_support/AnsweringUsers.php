<?php

declare(strict_types=1);

namespace ws\tests\_support;

use common\models\User;
use common\repositories\UserRepositoryInterface;
use DateTimeImmutable;
use LogicException;
use Override;
use yii\db\Exception;

/**
 * Репозиторий админов для WS-сервера, который на каждый следующий findActiveById отвечает следующим ответом
 * из списка: админом, null (заблокирован) или исключением БД. Первый ответ — для auth, следующие — для перепроверок
 * на ping. Остальное WS-серверу не нужно и падает.
 */
final class AnsweringUsers implements UserRepositoryInterface
{
    /** @var list<User|Exception|null> */
    private array $answers;

    public function __construct(User|Exception|null ...$answers)
    {
        $this->answers = array_values($answers);
    }

    #[Override]
    public function findActiveById(int $id): ?User
    {
        if ($this->answers === []) {
            throw new LogicException('Ответы репозитория кончились');
        }

        $answer = array_shift($this->answers);
        if ($answer instanceof Exception) {
            throw $answer;
        }

        return $answer;
    }

    #[Override]
    public function findByUsername(string $username): ?User
    {
        throw new LogicException('WS-серверу не нужен поиск по логину');
    }

    #[Override]
    public function create(string $username, string $passwordHash, string $authKey, DateTimeImmutable $now): User
    {
        throw new LogicException('WS-сервер не создаёт админов');
    }

    #[Override]
    public function setStatus(int $id, int $status, string $authKey, DateTimeImmutable $now): void
    {
        throw new LogicException('WS-сервер не меняет статус');
    }

    #[Override]
    public function setPassword(int $id, string $passwordHash, string $authKey, DateTimeImmutable $now): void
    {
        throw new LogicException('WS-сервер не меняет пароль');
    }
}
