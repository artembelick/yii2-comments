import { defineConfig, devices } from "@playwright/test";

import { requireEnv } from "./support/env.ts";

/**
 * Проекты по набору и ярусу (docs/test-plans/README.md, «Где лежат тесты»).
 * Makefile запускает их по очереди: smoke → regression-ws-parallel → regression-ws-exclusive → e2e-parallel → e2e-exclusive.
 */
const chromium = {
  ...devices["Desktop Chrome"],
  // Chromium резолвит *.localhost в свой 127.0.0.1, не спрашивая DNS. На стенде оба хоста — это nginx.
  launchOptions: { args: ["--host-resolver-rules=MAP *.localhost nginx"] },
};

export default defineConfig({
  testDir: "./tests",
  testMatch: "**/*.spec.ts",
  forbidOnly: true,
  // ДТ-6: флейк — это баг, а не повод перезапустить.
  retries: 0,
  // Локально один воркер; ярус parallel масштабируется шардами CI (--shard).
  workers: 1,
  fullyParallel: false,
  timeout: 30_000,
  expect: { timeout: 5000 },
  outputDir: "test-results/artifacts",
  reporter: [
    ["list"],
    ["junit", { outputFile: "test-results/junit.xml" }],
    ["html", { open: "never", outputFolder: "playwright-report" }],
  ],
  use: {
    baseURL: requireEnv("PUBLIC_URL"),
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [
    { name: "smoke", testDir: "./tests/smoke", use: chromium },
    { name: "regression-ws-parallel", testDir: "./tests/regression-ws/parallel", use: chromium },
    { name: "regression-ws-exclusive", testDir: "./tests/regression-ws/exclusive", use: chromium },
    { name: "e2e-parallel", testDir: "./tests/e2e/parallel", use: chromium },
    { name: "e2e-exclusive", testDir: "./tests/e2e/exclusive", use: chromium },
  ],
});
