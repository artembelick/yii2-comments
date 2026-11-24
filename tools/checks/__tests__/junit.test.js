// @ts-check
import { describe, expect, it } from "vitest";

import { checkJunit } from "../junit.js";

/**
 * @param {string} cases
 * @returns {string}
 */
const junit = (cases) =>
  `<?xml version="1.0" encoding="UTF-8"?>\n<testsuites><testsuite name="unit" tests="1">${cases}</testsuite></testsuites>\n`;

describe("строгость PHPUnit по junit", () => {
  it("зелёные тесты → нарушений нет", () => {
    const xml = junit(String.raw`<testcase name="testTrims" class="common\tests\unit\NormalizerTest" assertions="1"/>`);

    expect(checkJunit(xml)).toStrictEqual([]);
  });

  it.each([
    ["тест без проверок (useless)", "<error>Useless Test</error>", "тест без проверок (ПР-5)"],
    ["пропущенный тест", "<skipped/>", "тест пропущен (skip запрещён)"],
    [
      "предупреждение PHPUnit",
      String.raw`<warning type="PHPUnit\Framework\Warning">x</warning>`,
      "предупреждение PHPUnit",
    ],
  ])("%s → нарушение с именем теста", (_case, fault, reason) => {
    const xml = junit(`<testcase name="testTrims" class="common\\tests\\unit\\NormalizerTest">${fault}</testcase>`);

    expect(checkJunit(xml)).toStrictEqual([`common\\tests\\unit\\NormalizerTest::testTrims: ${reason}`]);
  });

  it("не junit → нарушение", () => {
    expect(checkJunit("{}")).toStrictEqual(["отчёт тестов не в формате junit"]);
  });
});
