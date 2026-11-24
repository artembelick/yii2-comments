import type { Faker } from "@faker-js/faker";
import { test as base } from "@playwright/test";
import type { APIRequestContext, Page } from "@playwright/test";
import type { Redis } from "ioredis";
import type { Pool } from "pg";

import { logIn } from "./admin.ts";
import { requireEnv } from "./env.ts";
import { admin, connectDb } from "./factories.ts";
import { seededFaker, seedFromTitle } from "./faker.ts";
import { connectRedis, resetRateLimits } from "./redis.ts";
import { openAuthenticatedWs, openWs } from "./ws.ts";
import type { WsProbe } from "./ws.ts";

interface TestFixtures {
  faker: Faker;
  /** Гость в своём browser context (БР-2) на публичной части. Счётчики лимита перед тестом сброшены. */
  guest: Page;
  /** HTTP-клиент гостя без браузера для REG-WS: отправляет форму через postComment. Счётчики лимита сброшены. */
  guestApi: APIRequestContext;
  /** HTTP-клиент без браузера, вошедший свежим админом из фабрики: для токена WS в REG-WS. */
  adminApi: APIRequestContext;
  /** Соединения REG-WS. Всё, что тест открыл, закрывается после теста, а не в его теле. */
  ws: { open: () => Promise<WsProbe>; openAuthenticated: (token: string) => Promise<WsProbe> };
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
  guestApi: async ({ playwright, redis }, use) => {
    await resetRateLimits(redis);
    const request = await playwright.request.newContext();
    await use(request);
    await request.dispose();
  },
  adminApi: async ({ playwright, db, faker }, use) => {
    const request = await playwright.request.newContext();
    await logIn(request, await admin(db, faker));
    await use(request);
    await request.dispose();
  },
  ws: async ({}, use) => {
    const probes: WsProbe[] = [];
    const track = (probe: WsProbe): WsProbe => {
      probes.push(probe);

      return probe;
    };
    await use({
      open: async () => track(await openWs()),
      openAuthenticated: async (token) => track(await openAuthenticatedWs(token)),
    });
    for (const probe of probes) {
      probe.close();
    }
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
