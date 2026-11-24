// @ts-check
import { describe, expect, it } from "vitest";

import { TEST_FILES } from "../globs.js";
import { strictAssertions } from "../rules/strict-assertions.js";

const PHP = "backend/tests/unit/ATest.php";
const JS = "backend/web/js/__tests__/a.test.js";
const E2E = "e2e/tests/smoke/a.spec.ts";

describe("strict-assertions (ПР-4)", () => {
  it.each([
    [PHP, "<?php\n$this->assertEquals(1, $a);\n", "assertEquals", "assertSame"],
    [PHP, "<?php\nself::assertEqualsCanonicalizing([1], $a);\n", "assertEqualsCanonicalizing", "assertSame"],
    [PHP, "<?php\n$this->assertNotEquals(1, $a);\n", "assertNotEquals", "assertNotSame"],
    [JS, "\nexpect(a).toEqual({ b: 1 });\n", "toEqual", "toStrictEqual"],
    [JS, "\nexpect(a).toBeTruthy();\n", "toBeTruthy", "toBe(true)"],
    [E2E, "\nexpect(a).not.toBeFalsy();\n", "toBeFalsy", "toBe(false)"],
  ])("%s: «%s» → нарушение на строке 2", (path, text, found, instead) => {
    expect(strictAssertions.check({ path, text })).toStrictEqual([
      { line: 2, message: `«${found}» — нестрогая проверка: используйте ${instead}` },
    ]);
  });

  it.each([
    ["assertSame", PHP, "<?php\n$this->assertSame(1, $a);\n"],
    ["toStrictEqual", JS, "\nexpect(a).toStrictEqual({ b: 1 });\n"],
    ["слово в строке", E2E, "\ntest('SMOKE-01: toEqual не нужен', async () => {});\n"],
  ])("%s → нарушений нет", (_case, path, text) => {
    expect(strictAssertions.check({ path, text })).toStrictEqual([]);
  });

  it("применяется ко всем тестам проекта", () => {
    expect(strictAssertions.files).toStrictEqual(TEST_FILES);
  });
});
