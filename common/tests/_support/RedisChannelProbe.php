<?php

declare(strict_types=1);

namespace common\tests\_support;

use RuntimeException;
use UnexpectedValueException;
use Yii;
use yii\redis\Connection;

/**
 * Подписчик на канал Redis для REST-regression (REG-A-70, A-71): своё TCP-соединение с SUBSCRIBE,
 * потому что yii2-redis сообщения подписки не читает. «Ровно одно событие» и «событий нет» проверяются
 * без ожидания по времени (ДТ-3): после действия тест публикует в тот же канал метку, и всё, что пришло
 * до неё, — события действия. Redis отдаёт сообщения канала в порядке публикации.
 */
final class RedisChannelProbe
{
    /** Предел ожидания ответа Redis: сбой стенда роняет тест, а не вешает его. */
    private const int READ_TIMEOUT_SECONDS = 5;

    /** @var resource */
    private $socket;

    private function __construct(private readonly string $channel)
    {
        /** @var Connection $redis */
        $redis = Yii::$app->get('redis');
        $socket = stream_socket_client(sprintf('tcp://%s:%d', $redis->hostname, $redis->port), $code, $error, self::READ_TIMEOUT_SECONDS);
        if ($socket === false) {
            throw new RuntimeException(sprintf('Нет соединения с Redis: %s', $error));
        }

        stream_set_timeout($socket, self::READ_TIMEOUT_SECONDS);
        $this->socket = $socket;
        $this->write(['SUBSCRIBE', $channel]);
        $this->read();
    }

    /** Подписка на канал: возвращается, когда Redis подтвердил SUBSCRIBE. */
    public static function subscribe(string $channel): self
    {
        return new self($channel);
    }

    /**
     * Объекты comment из событий: тесту не нужно разбирать mixed прямо в проверке (ЧТ-4).
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    public static function commentsOf(array $messages): array
    {
        $comments = [];
        foreach ($messages as $message) {
            $comment = $message['comment'] ?? null;
            if (!is_array($comment)) {
                throw new UnexpectedValueException('В событии нет объекта comment');
            }

            /** @var array<string, mixed> $comment */
            $comments[] = $comment;
        }

        return $comments;
    }

    /**
     * Публикует метку и возвращает разобранные JSON-сообщения, пришедшие до неё. Соединение после этого закрыто.
     *
     * @return list<array<string, mixed>>
     */
    public function messagesUntilMarker(): array
    {
        $marker = json_encode(['type' => 'test.marker', 'id' => bin2hex(random_bytes(8))], JSON_THROW_ON_ERROR);
        Yii::$app->get('redis')->executeCommand('PUBLISH', [$this->channel, $marker]);
        $messages = [];
        while (($payload = $this->nextPayload()) !== $marker) {
            /** @var array<string, mixed> $message */
            $message = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $messages[] = $message;
        }

        fclose($this->socket);

        return $messages;
    }

    /** Текст следующего сообщения канала: ответ ["message", channel, payload]. */
    private function nextPayload(): string
    {
        $reply = $this->read();
        if (!is_array($reply) || ($reply[0] ?? null) !== 'message' || !is_string($reply[2] ?? null)) {
            throw new RuntimeException('Redis прислал не сообщение канала: ' . json_encode($reply));
        }

        return $reply[2];
    }

    /**
     * @param list<string> $command
     */
    private function write(array $command): void
    {
        $resp = sprintf('*%d', count($command)) . "\r\n";
        foreach ($command as $part) {
            $resp .= sprintf('$%d', strlen($part)) . "\r\n" . $part . "\r\n";
        }

        fwrite($this->socket, $resp);
    }

    /** Один ответ RESP2: строка, число, массив или null. */
    private function read(): mixed
    {
        $line = fgets($this->socket);
        if ($line === false) {
            throw new RuntimeException(sprintf('Redis не ответил за %d с', self::READ_TIMEOUT_SECONDS));
        }

        $type = $line[0];
        $value = substr($line, 1, -2);

        return match ($type) {
            '+' => $value,
            ':' => (int) $value,
            '$' => $value === '-1' ? null : $this->readBulk((int) $value),
            '*' => $this->readArray((int) $value),
            default => throw new RuntimeException('Ошибка Redis: ' . $value),
        };
    }

    /**
     * @return list<mixed>
     */
    private function readArray(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; ++$i) {
            $items[] = $this->read();
        }

        return $items;
    }

    private function readBulk(int $length): string
    {
        $data = '';
        while (strlen($data) < $length + 2) {
            $chunk = fread($this->socket, max(1, $length + 2 - strlen($data)));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException(sprintf('Redis не дослал ответ за %d с', self::READ_TIMEOUT_SECONDS));
            }

            $data .= $chunk;
        }

        return substr($data, 0, $length);
    }
}
