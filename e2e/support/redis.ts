import { Redis } from "ioredis";

import { requireEnv } from "./env.ts";

/**
 * Доступ к Redis стенда. Тесты сбрасывают счётчики лимита и публикуют управляющие сообщения WS;
 * сессии и токены не задеваются.
 */
export function connectRedis(): Redis {
  return new Redis({ host: requireEnv("REDIS_HOST"), port: Number(requireEnv("REDIS_PORT")), lazyConnect: true });
}

/** Сбрасывает счётчики rate limit: все тесты Playwright приходят на стенд с одного IP. */
export async function resetRateLimits(redis: Redis): Promise<void> {
  const keys = await redis.keys("rate-limit:*");
  await Promise.all(keys.map(async (key) => redis.del(key)));
}

/** Сообщение в ws.control, как его публикует common\security\SessionTerminator::accessRevoked. */
export async function publishAccessRevoked(redis: Redis, userId: number): Promise<void> {
  await redis.publish("ws.control", JSON.stringify({ type: "access.revoked", userId }));
}
