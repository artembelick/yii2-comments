import type { Faker } from "@faker-js/faker";
import { test as base } from "@playwright/test";
import type { Redis } from "ioredis";
import type { Pool } from "pg";

import { connectDb } from "./factories.ts";
import { seededFaker, seedFromTitle } from "./faker.ts";
import { connectRedis } from "./redis.ts";

interface TestFixtures {
  faker: Faker;
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
