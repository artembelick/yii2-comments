// @ts-check
/** Где лежат тесты (docs/test-plans/README.md, «Где лежат тесты»). */

export const PHP_TEST_FILES = "{common,frontend,backend,console,ws}/tests/{unit,regression}/**/*.php";
export const VITEST_FILES = "backend/web/js/__tests__/**/*.test.js";
export const PLAYWRIGHT_FILES = "e2e/tests/**/*.spec.ts";

export const TEST_FILES = [PHP_TEST_FILES, VITEST_FILES, PLAYWRIGHT_FILES];
