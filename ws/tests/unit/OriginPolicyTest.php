<?php

declare(strict_types=1);

namespace ws\tests\unit;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use PHPUnit\Framework\Attributes\TestDox;
use ws\OriginPolicy;

final class OriginPolicyTest extends Unit
{
    private const string ADMIN = 'http://admin.comments.localhost:8080';

    #[TestDox('UNIT-ORIG-01: точное совпадение схемы, хоста и порта с разрешённым Origin → true')]
    public function testExactOriginIsAllowed(): void
    {
        $policy = new OriginPolicy(['http://other.example', self::ADMIN]);

        $allowed = $policy->isAllowed(self::ADMIN);

        self::assertTrue($allowed);
    }

    #[TestDox('UNIT-ORIG-02: чужой, пустой или неточный Origin → false')]
    #[DataProvider('foreignOrigins')]
    public function testForeignOriginIsRejected(?string $origin): void
    {
        $policy = new OriginPolicy([self::ADMIN]);

        $allowed = $policy->isAllowed($origin);

        self::assertFalse($allowed);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function foreignOrigins(): array
    {
        return [
            'заголовка нет' => [null],
            'пустая строка' => [''],
            'публичная часть' => ['http://comments.localhost:8080'],
            'хост админки как префикс чужого' => ['http://admin.comments.localhost.evil.example:8080'],
            'https вместо http' => ['https://admin.comments.localhost:8080'],
            'другой порт' => ['http://admin.comments.localhost:8081'],
            'без порта' => ['http://admin.comments.localhost'],
            'со слэшем в конце' => [self::ADMIN . '/'],
        ];
    }
}
