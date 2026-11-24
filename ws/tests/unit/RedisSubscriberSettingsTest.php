<?php

declare(strict_types=1);

namespace ws\tests\unit;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use PHPUnit\Framework\Attributes\TestDox;
use ws\RedisSubscriberSettings;
use yii\redis\Connection;

final class RedisSubscriberSettingsTest extends Unit
{
    /**
     * @param array<string, mixed> $config
     */
    #[TestDox('UNIT-SUB-01: адрес подписчика — хост и порт компонента redis или его unix-сокет')]
    #[DataProvider('addresses')]
    public function testAddressFollowsComponent(array $config, string $expected): void
    {
        $redis = new Connection($config);

        $settings = RedisSubscriberSettings::of($redis);

        self::assertSame($expected, $settings->address);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function addresses(): array
    {
        return [
            'хост и порт' => [['hostname' => 'redis.test', 'port' => 6380], 'redis://redis.test:6380'],
            'unix-сокет' => [['hostname' => 'redis.test', 'unixSocket' => '/run/redis.sock'], 'unix:///run/redis.sock'],
        ];
    }

    #[TestDox('UNIT-SUB-02: таймаут соединения, TLS и опции потока берутся у компонента redis')]
    public function testOptionsFollowComponent(): void
    {
        $redis = new Connection([
            'connectionTimeout' => 2.5,
            'useSSL' => true,
            'contextOptions' => ['ssl' => ['verify_peer' => true]],
        ]);

        $settings = RedisSubscriberSettings::of($redis);

        self::assertSame(
            ['connect_timeout' => 2.5, 'ssl' => true, 'context' => ['ssl' => ['verify_peer' => true]]],
            $settings->options,
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param string|list<string>|null $expected
     */
    #[TestDox('UNIT-SUB-03: учётные данные AUTH берутся у компонента redis')]
    #[DataProvider('credentials')]
    public function testAuthFollowsComponent(array $config, string|array|null $expected): void
    {
        $redis = new Connection($config);

        $settings = RedisSubscriberSettings::of($redis);

        self::assertSame($expected, $settings->auth);
    }

    /**
     * @return array<string, array{array<string, mixed>, string|list<string>|null}>
     */
    public static function credentials(): array
    {
        return [
            'без пароля → AUTH не нужен' => [[], null],
            'пароль' => [['password' => 'secret'], 'secret'],
            'логин и пароль (ACL)' => [['username' => 'ws', 'password' => 'secret'], ['ws', 'secret']],
        ];
    }
}
