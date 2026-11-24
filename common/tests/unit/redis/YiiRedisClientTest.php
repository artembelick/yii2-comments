<?php

declare(strict_types=1);

namespace common\tests\unit\redis;

use Closure;
use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\redis\RedisUnavailableException;
use common\redis\YiiRedisClient;
use PHPUnit\Framework\Attributes\TestDox;
use yii\db\Exception;
use yii\redis\Connection;

final class YiiRedisClientTest extends Unit
{
    /**
     * @param Closure(YiiRedisClient): mixed $call
     * @param list<int|string> $params
     */
    #[TestDox('UNIT-RDS-01: каждый метод чтения отправляет свою команду Redis и приводит ответ к типу интерфейса')]
    #[DataProvider('queries')]
    public function testQueriesSendCommands(Closure $call, string $command, array $params, ?string $reply, mixed $expected): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeCommand')->with($command, $params)->willReturn($reply);

        $result = $call(new YiiRedisClient($connection));

        self::assertSame($expected, $result);
    }

    /**
     * @return array<string, array{Closure(YiiRedisClient): mixed, string, list<int|string>, ?string, mixed}>
     */
    public static function queries(): array
    {
        return [
            'incr → INCR, число' => [static fn(YiiRedisClient $redis): int => $redis->incr('k'), 'INCR', ['k'], '3', 3],
            'get → GET, строка' => [static fn(YiiRedisClient $redis): ?string => $redis->get('k'), 'GET', ['k'], '5', '5'],
            'get → GET, ключа нет' => [static fn(YiiRedisClient $redis): ?string => $redis->get('k'), 'GET', ['k'], null, null],
            'getDel → GETDEL, строка' => [static fn(YiiRedisClient $redis): ?string => $redis->getDel('k'), 'GETDEL', ['k'], 'v', 'v'],
            'getDel → GETDEL, ключа нет' => [static fn(YiiRedisClient $redis): ?string => $redis->getDel('k'), 'GETDEL', ['k'], null, null],
        ];
    }

    /**
     * @param Closure(YiiRedisClient): void $call
     * @param list<int|string> $params
     */
    #[TestDox('UNIT-RDS-01: каждый метод записи отправляет свою команду Redis с аргументами')]
    #[DataProvider('writes')]
    public function testWritesSendCommands(Closure $call, string $command, array $params): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeCommand')->with($command, $params)->willReturn('OK');

        $call(new YiiRedisClient($connection));
    }

    /**
     * @return array<string, array{Closure(YiiRedisClient): void, string, list<int|string>}>
     */
    public static function writes(): array
    {
        return [
            'expire → EXPIRE' => [static fn(YiiRedisClient $redis) => $redis->expire('k', 60), 'EXPIRE', ['k', 60]],
            'setNxEx → SET NX EX' => [static fn(YiiRedisClient $redis) => $redis->setNxEx('k', 'v', 60), 'SET', ['k', 'v', 'NX', 'EX', 60]],
            'del → DEL' => [static fn(YiiRedisClient $redis) => $redis->del('k'), 'DEL', ['k']],
        ];
    }

    #[TestDox('UNIT-RDS-02: ошибка соединения yii2-redis → RedisUnavailableException')]
    public function testConnectionFailureBecomesUnavailable(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('executeCommand')->willThrowException(new Exception('Failed to open redis DB connection'));

        $this->expectException(RedisUnavailableException::class);

        (new YiiRedisClient($connection))->get('k');
    }
}
