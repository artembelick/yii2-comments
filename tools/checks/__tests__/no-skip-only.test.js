// @ts-check
import { describe, expect, it } from "vitest";

import { TEST_FILES } from "../globs.js";
import { noSkipOnly } from "../rules/no-skip-only.js";

describe("no-skip-only", () => {
  it.each([
    ["markTestSkipped", "a/tests/unit/ATest.php", "<?php\n$this->markTestSkipped();\n"],
    ["markTestIncomplete", "a/tests/unit/ATest.php", "<?php\n$this->markTestIncomplete();\n"],
    ["$scenario->skip", "a/tests/regression/ACest.php", "<?php\n$scenario->skip();\n"],
    ["$scenario->incomplete", "a/tests/regression/ACest.php", "<?php\n$scenario->incomplete();\n"],
    ["аннотация @skip", "a/tests/unit/ATest.php", "<?php\n/** @skip */\n"],
    ["атрибут Skip", "a/tests/unit/ATest.php", "<?php\n#[Skip]\n"],
    ["атрибут Incomplete", "a/tests/unit/ATest.php", "<?php\n#[\\Codeception\\Attribute\\Incomplete]\n"],
    ["закомментированный PHP-тест", "a/tests/unit/ATest.php", "<?php\n// public function testA(): void\n"],
    ["закомментированный Cest", "a/tests/unit/ACest.php", "<?php\n// public function a(RegressionTester $I): void\n"],
    ["test.skip", "e2e/tests/a.spec.ts", "\ntest.skip('E2E-01: …', () => {});\n"],
    ["test.only", "e2e/tests/a.spec.ts", "\ntest.only('E2E-01: …', () => {});\n"],
    ["test.fixme", "e2e/tests/a.spec.ts", "\ntest.fixme('E2E-01: …', () => {});\n"],
    ["test.describe.only", "e2e/tests/a.spec.ts", "\ntest.describe.only('…', () => {});\n"],
    ["it.todo", "x/__tests__/a.test.js", "\nit.todo('…');\n"],
    ["describe.skip", "x/__tests__/a.test.js", "\ndescribe.skip('…', () => {});\n"],
    ["xit", "x/__tests__/a.test.js", "\nxit('…', () => {});\n"],
    ["fdescribe", "x/__tests__/a.test.js", "\nfdescribe('…', () => {});\n"],
    ["закомментированный JS-тест", "x/__tests__/a.test.js", "\n// it('…', () => {});\n"],
  ])("%s → нарушение на строке 2", (_case, path, text) => {
    const violations = noSkipOnly.check({ path, text });

    expect(violations.map(({ line }) => line)).toStrictEqual([2]);
  });

  it("сообщение называет найденную конструкцию", () => {
    const violations = noSkipOnly.check({ path: "e2e/tests/a.spec.ts", text: "test.only('E2E-01', () => {});" });

    expect(violations).toStrictEqual([
      { line: 1, message: "«test.only» запрещён: тест либо работает и проходит, либо удаляется" },
    ]);
  });

  it.each([
    ["слово skip в строке", "e2e/tests/a.spec.ts", "test('E2E-01: test.skip в тексте', () => {});"],
    ["обычный тест", "x/__tests__/a.test.js", "it('UNIT-JS-BO-01: …', () => {});"],
    ["метод skip у объекта", "x/__tests__/a.test.js", "it('UNIT-JS-BO-01: …', () => { list.skip(1); });"],
  ])("%s → нарушений нет", (_case, path, text) => {
    expect(noSkipOnly.check({ path, text })).toStrictEqual([]);
  });

  it("применяется ко всем тестам проекта", () => {
    expect(noSkipOnly.files).toStrictEqual(TEST_FILES);
  });
});
