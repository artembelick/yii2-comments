// @ts-check
import { describe, expect, it } from "vitest";

import { noLogicInTests } from "../rules/no-logic-in-tests.js";
import { TEST_FILES } from "../test-locations.js";

const PHP = "common/tests/unit/ATest.php";
const JS = "backend/web/js/__tests__/a.test.js";
const E2E = "e2e/tests/smoke/a.spec.ts";

describe("no-logic-in-tests (ЧТ-4)", () => {
  it.each([
    [PHP, "<?php\nif ($a) {}\n", "if"],
    [PHP, "<?php\nforeach ($rows as $row) {}\n", "foreach"],
    [PHP, "<?php\ntry {\n", "try"],
    [PHP, "<?php\n} catch (Throwable) {\n", "catch"],
    [PHP, "<?php\n$a = match ($b) {};\n", "match"],
    [PHP, "<?php\n$a = $b ? 1 : 2;\n", "?"],
    [JS, "\nfor (const row of rows) {}\n", "for"],
    [JS, "\nwhile (a) {}\n", "while"],
    [JS, "\nswitch (a) {}\n", "switch"],
    [E2E, "\n} else {\n", "else"],
  ])("%s: «%s» → нарушение на строке 2", (path, text, construct) => {
    expect(noLogicInTests.check({ path, text })).toStrictEqual([
      {
        line: 2,
        message: `«${construct}» в тесте: варианты — через провайдер, исключения — через expectException/toThrow, хелперы — в support`,
      },
    ]);
  });

  it.each([
    ["провайдер на yield", PHP, "<?php\npublic static function names(): iterable { yield 'одна буква' => ['Я']; }\n"],
    ["опциональное поле TS", E2E, "\ntype Admin = { login?: string };\n"],
    ["nullsafe и ??", PHP, "<?php\n$a = $b?->c ?? 'd';\n"],
    ["ветвление в тексте теста", JS, "\nit('UNIT-JS-BO-01: if reset, then 1000', () => {});\n"],
    ["метод match у строки", JS, "\nexpect(text.match(/a/u)).toStrictEqual(null);\n"],
  ])("%s → нарушений нет", (_case, path, text) => {
    expect(noLogicInTests.check({ path, text })).toStrictEqual([]);
  });

  it("применяется ко всем тестам проекта", () => {
    expect(noLogicInTests.files).toStrictEqual(TEST_FILES);
  });
});
