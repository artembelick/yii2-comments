// @ts-check
import { randomInt } from "node:crypto";

import { defineConfig } from "vitest/config";

// Порядок тестов перемешивается (ИЗ-1). Seed печатается, упавший прогон повторяется с тем же seed.
const seed = Number(process.env["VITEST_SEED"] ?? randomInt(1, 2 ** 31));
process.stdout.write(`[vitest] seed ${seed}. Повторить: VITEST_SEED=${seed} make test-unit\n`);

export default defineConfig({
  test: {
    sequence: { shuffle: true, seed },
    // ИЗ-4: всё глобальное, что поменял тест, возвращается на место.
    restoreMocks: true,
    unstubEnvs: true,
    unstubGlobals: true,
    // ПР-5: тест без проверок падает.
    expect: { requireAssertions: true },
    reporters: ["default", "junit"],
    outputFile: { junit: "tests/_output/vitest-junit.xml" },
    projects: [
      {
        extends: true,
        test: {
          name: "admin-js",
          environment: "jsdom",
          include: ["backend/web/js/__tests__/**/*.test.js"],
        },
      },
      {
        extends: true,
        test: {
          name: "tools",
          environment: "node",
          include: ["tools/**/__tests__/**/*.test.js"],
          // Деревья-фикстуры CLI содержат файлы с именами тестов, но это данные, а не тесты.
          exclude: ["tools/**/__tests__/fixtures/**"],
        },
      },
    ],
    coverage: {
      provider: "v8",
      include: ["backend/web/js/**/*.js", "tools/**/*.js"],
      exclude: [
        "**/__tests__/**",
        // Entry point'ы: только связывают модули, ветвлений в них нет (проверяет tools/checks, правило entrypoint-limits).
        "backend/web/js/main.js",
        "tools/checks/bin.js",
      ],
      thresholds: { lines: 100, functions: 100, branches: 100, statements: 100 },
      reporter: ["text-summary", "lcov"],
      reportsDirectory: "tests/_output/coverage-js",
    },
  },
});
