<?php

declare(strict_types=1);

namespace ws;

/**
 * Защита от CSWSH: handshake принимается только с Origin админки. Сравнение строгое, целиком:
 * схема, хост и порт должны совпасть с одним из разрешённых (WS_ALLOWED_ORIGINS).
 */
final readonly class OriginPolicy
{
    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(private array $allowedOrigins) {}

    public function isAllowed(?string $origin): bool
    {
        return in_array($origin, $this->allowedOrigins, true);
    }
}
