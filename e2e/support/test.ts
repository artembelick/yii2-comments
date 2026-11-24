import type { Faker } from "@faker-js/faker";
import { test as base } from "@playwright/test";
import type { Page } from "@playwright/test";
import type { Redis } from "ioredis";
import type { Pool } from "pg";

import { requireEnv } from "./env.ts";
import { connectDb } from "./factories.ts";
import { seededFaker, seedFromTitle } from "./faker.ts";
import { connectRedis, resetRateLimits } from "./redis.ts";

interface TestFixtures {
  faker: Faker;
  /** Гость в своём browser context (БР-2) на публичной части. Счётчики лимита перед тестом сброшены. */
  guest: Page;
}

interface WorkerFixtures {
  db: Pool;
  redis: Redis;
}

/** Общий `test` для всех наборов: Faker с seed из ID теста, соединения со стендом на воркер. */
export const test = base.extend<TestFixtures, WorkerFixtures>({
  faker: async ({}, use, testInfo) => {
    const seed = seedFromTitle(testInfo.title);
    // Seed виден в отчёте о падении.
    testInfo.annotations.push({ type: "faker-seed", description: String(seed) });
    await use(seededFaker(seed));
  },
  guest: async ({ browser, redis }, use) => {
    await resetRateLimits(redis);
    const context = await browser.newContext({ baseURL: requireEnv("PUBLIC_URL") });
    await use(await context.newPage());
    await context.close();
  },
  db: [
    async ({}, use): Promise<void> => {
      const pool = connectDb();
      await use(pool);
      await pool.end();
    },
    { scope: "worker" },
  ],
  redis: [
    async ({}, use): Promise<void> => {
      const redis = connectRedis();
      await use(redis);
      redis.disconnect();
    },
    { scope: "worker" },
  ],
});

export { expect } from "@playwright/test";
