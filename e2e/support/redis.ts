import { Redis } from "ioredis";

import { requireEnv } from "./env.ts";

/** Доступ к Redis стенда. Тесты трогают только счётчики лимита, сессии и токены не задеваются. */
export function connectRedis(): Redis {
  return new Redis({ host: requireEnv("REDIS_HOST"), port: Number(requireEnv("REDIS_PORT")), lazyConnect: true });
}

/** Сбрасывает счётчики rate limit: все тесты Playwright приходят на стенд с одного IP. */
export async function resetRateLimits(redis: Redis): Promise<void> {
  const keys = await redis.keys("rate-limit:*");
  await Promise.all(keys.map(async (key) => redis.del(key)));
}
