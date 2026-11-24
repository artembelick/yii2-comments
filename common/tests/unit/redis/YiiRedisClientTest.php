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
    #[TestDox('UNIT-RDS-01: каждый метод отправляет свою команду Redis и приводит ответ к типу интерфейса')]
    #[DataProvider('commands')]
    public function testMethodsSendCommands(Closure $call, string $command, array $params, ?string $reply, mixed $expected): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeCommand')->with($command, $params)->willReturn($reply);

        $result = $call(new YiiRedisClient($connection));

        self::assertSame($expected, $result);
    }

    /**
     * @return array<string, array{Closure(YiiRedisClient): mixed, string, list<int|string>, ?string, mixed}>
     */
    public static function commands(): array
    {
        return [
            'incr → INCR, число' => [static fn(YiiRedisClient $redis): int => $redis->incr('k'), 'INCR', ['k'], '3', 3],
            'expire → EXPIRE' => [
                static function (YiiRedisClient $redis): null {
                    $redis->expire('k', 60);

                    return null;
                },
                'EXPIRE',
                ['k', 60],
                '1',
                null,
            ],
            'get → GET, строка' => [static fn(YiiRedisClient $redis): ?string => $redis->get('k'), 'GET', ['k'], '5', '5'],
            'get → GET, ключа нет' => [static fn(YiiRedisClient $redis): ?string => $redis->get('k'), 'GET', ['k'], null, null],
            'del → DEL' => [
                static function (YiiRedisClient $redis): null {
                    $redis->del('k');

                    return null;
                },
                'DEL',
                ['k'],
                '1',
                null,
            ],
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
