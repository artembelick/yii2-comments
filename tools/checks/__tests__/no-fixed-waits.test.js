// @ts-check
import { describe, expect, it } from "vitest";

import { noFixedWaits } from "../rules/no-fixed-waits.js";
import { TEST_FILES } from "../test-locations.js";

const PHP = "frontend/tests/regression/ACest.php";
const E2E = "e2e/tests/e2e/exclusive/a.spec.ts";

describe("no-fixed-waits (ДТ-3)", () => {
  it.each([
    [PHP, "<?php\nsleep(1);\n", "sleep("],
    [PHP, "<?php\nusleep(1000);\n", "usleep("],
    [PHP, "<?php\n\\usleep(1000);\n", "usleep("],
    [PHP, "<?php\ntime_nanosleep(0, 1);\n", "time_nanosleep("],
    [E2E, "\nawait page.waitForTimeout(500);\n", "waitForTimeout("],
    [E2E, "\nawait new Promise((r) => setTimeout(r, 500));\n", "setTimeout("],
  ])("%s: «%s» → нарушение на строке 2", (path, text, found) => {
    expect(noFixedWaits.check({ path, text })).toStrictEqual([
      { line: 2, message: `«${found}» — фиксированное ожидание: ждите условие (автоожидание, expect.poll, toPass)` },
    ]);
  });

  it.each([
    [
      "setTimeout в Vitest с фейковыми таймерами",
      "backend/web/js/__tests__/a.test.js",
      "\nsetTimeout(callback, 1000);\n",
    ],
    ["метод с тем же именем", PHP, "<?php\n$timer->sleep(1);\n"],
    ["слово в строке", E2E, "\ntest('E2E-01: без sleep(1)', async () => {});\n"],
  ])("%s → нарушений нет", (_case, path, text) => {
    expect(noFixedWaits.check({ path, text })).toStrictEqual([]);
  });

  it("применяется ко всем тестам проекта", () => {
    expect(noFixedWaits.files).toStrictEqual(TEST_FILES);
  });
});
