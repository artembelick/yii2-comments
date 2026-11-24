// @ts-check
/** Где лежат тесты (docs/test-plans/README.md, «Где лежат тесты»). */

export const PHP_TEST_FILES = "{common,frontend,backend,console,ws}/tests/{unit,regression}/**/*.php";
export const VITEST_FILES = "backend/web/js/__tests__/**/*.test.js";
export const PLAYWRIGHT_FILES = "e2e/tests/**/*.spec.ts";

/** Тесты самих проверок. Правила rules.md действуют и на них, но ID из тест-планов у них нет: это не поведение продукта. */
export const TOOLS_TEST_FILES = "tools/checks/__tests__/*.test.js";

export const PRODUCT_TEST_FILES = [PHP_TEST_FILES, VITEST_FILES, PLAYWRIGHT_FILES];
export const TEST_FILES = [...PRODUCT_TEST_FILES, TOOLS_TEST_FILES];
