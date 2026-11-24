<?php

declare(strict_types=1);

namespace ws;

/**
 * Соединения процесса WS-сервера и кто за ними стоит. Соединение после add ещё не авторизовано:
 * события и выборки по сессии и админу видят только авторизованные (ADR 0002).
 */
final class ConnectionRegistry
{
    /** @var array<string, ConnectionInterface> */
    private array $connections = [];

    /** @var array<string, array{userId: int, sessionId: string}> */
    private array $owners = [];

    public function add(ConnectionInterface $connection): void
    {
        $this->connections[$connection->id()] = $connection;
    }

    public function authenticate(ConnectionInterface $connection, int $userId, string $sessionId): void
    {
        $this->owners[$connection->id()] = ['userId' => $userId, 'sessionId' => $sessionId];
    }

    public function remove(ConnectionInterface $connection): void
    {
        unset($this->connections[$connection->id()], $this->owners[$connection->id()]);
    }

    public function isAuthenticated(ConnectionInterface $connection): bool
    {
        return isset($this->owners[$connection->id()]);
    }

    /**
     * @return list<ConnectionInterface>
     */
    public function authenticated(): array
    {
        return $this->select(static fn(): bool => true);
    }

    /**
     * @return list<ConnectionInterface>
     */
    public function bySession(string $sessionId): array
    {
        return $this->select(static fn(array $owner): bool => $owner['sessionId'] === $sessionId);
    }

    /**
     * @return list<ConnectionInterface>
     */
    public function byUser(int $userId): array
    {
        return $this->select(static fn(array $owner): bool => $owner['userId'] === $userId);
    }

    /**
     * Авторизованные соединения, чей владелец подходит под условие, в порядке добавления.
     *
     * @param callable(array{userId: int, sessionId: string}): bool $matches
     *
     * @return list<ConnectionInterface>
     */
    private function select(callable $matches): array
    {
        $ids = array_keys(array_filter($this->owners, $matches));

        return array_values(array_intersect_key($this->connections, array_flip($ids)));
    }
}
