<?php

declare(strict_types=1);

namespace common\tests\unit\security;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\redis\RedisClientInterface;
use common\redis\RedisUnavailableException;
use common\security\RateLimiter;
use common\security\RateLimiterUnavailableException;
use PHPUnit\Framework\Attributes\TestDox;

final class RateLimiterTest extends Unit
{
    private const string KEY = 'login:anna:10.0.0.1';

    private const string REDIS_KEY = 'rate-limit:login:anna:10.0.0.1';

    private const int LIMIT = 5;

    private const int WINDOW = 900;

    #[TestDox('UNIT-RL-01: первый hit в окне ставит EXPIRE на окно')]
    public function testFirstHitStartsWindow(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->method('incr')->with(self::REDIS_KEY)->willReturn(1);
        $redis->expects(self::once())->method('expire')->with(self::REDIS_KEY, self::WINDOW);

        (new RateLimiter($redis))->hit(self::KEY, self::LIMIT, self::WINDOW);
    }

    #[TestDox('UNIT-RL-01: последующие hit в окне EXPIRE не вызывают')]
    public function testNextHitsKeepWindow(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->method('incr')->willReturn(2);
        $redis->expects(self::never())->method('expire');

        (new RateLimiter($redis))->hit(self::KEY, self::LIMIT, self::WINDOW);
    }

    #[TestDox('UNIT-RL-02: hit в пределах лимита — true, сверх лимита — false')]
    #[DataProvider('counters')]
    public function testHitComparesCounterWithLimit(int $counter, bool $expected): void
    {
        $redis = self::createStub(RedisClientInterface::class);
        $redis->method('incr')->willReturn($counter);

        $allowed = (new RateLimiter($redis))->hit(self::KEY, self::LIMIT, self::WINDOW);

        self::assertSame($expected, $allowed);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function counters(): array
    {
        return [
            'INCR 1 → true' => [1, true],
            'INCR 2 → true' => [2, true],
            'INCR 3 → true' => [3, true],
            'INCR 4 → true' => [4, true],
            'INCR 5 → true' => [5, true],
            'INCR 6 → false' => [6, false],
        ];
    }

    #[TestDox('UNIT-RL-03: isExceeded — GET меньше лимита или ключа нет → false, лимит и больше → true')]
    #[DataProvider('storedCounters')]
    public function testIsExceededReadsCounter(?string $stored, bool $expected): void
    {
        $redis = self::createStub(RedisClientInterface::class);
        $redis->method('get')->willReturnMap([[self::REDIS_KEY, $stored]]);

        $exceeded = (new RateLimiter($redis))->isExceeded(self::KEY, self::LIMIT);

        self::assertSame($expected, $exceeded);
    }

    /**
     * @return array<string, array{?string, bool}>
     */
    public static function storedCounters(): array
    {
        return [
            'GET 4 → false' => ['4', false],
            'GET 5 → true' => ['5', true],
            'GET 6 → true' => ['6', true],
            'ключа нет → false' => [null, false],
        ];
    }

    #[TestDox('UNIT-RL-04: reset выполняет DEL ключа')]
    public function testResetDeletesCounter(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->expects(self::once())->method('del')->with(self::REDIS_KEY);

        (new RateLimiter($redis))->reset(self::KEY);
    }

    #[TestDox('UNIT-RL-05: Redis недоступен → RateLimiterUnavailableException из каждого метода')]
    #[DataProvider('operations')]
    public function testRedisFailureBecomesUnavailable(string $operation): void
    {
        $redis = self::createStub(RedisClientInterface::class);
        $redis->method($operation)->willThrowException(new RedisUnavailableException('connection refused'));
        $calls = [
            'incr' => static fn(RateLimiter $limiter): bool => $limiter->hit(self::KEY, self::LIMIT, self::WINDOW),
            'get' => static fn(RateLimiter $limiter): bool => $limiter->isExceeded(self::KEY, self::LIMIT),
            'del' => static function (RateLimiter $limiter): void {
                $limiter->reset(self::KEY);
            },
        ];

        $this->expectException(RateLimiterUnavailableException::class);

        $calls[$operation](new RateLimiter($redis));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function operations(): array
    {
        return [
            'hit' => ['incr'],
            'isExceeded' => ['get'],
            'reset' => ['del'],
        ];
    }
}
