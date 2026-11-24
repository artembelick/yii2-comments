// @ts-check
import { describe, expect, it } from "vitest";

import { extractPlanIds, implementedIds, testIds, unimplementedPlanIds } from "../rules/test-ids.js";
import { PRODUCT_TEST_FILES } from "../test-locations.js";

const PLAN = [
  {
    path: "docs/test-plans/unit.md",
    text: "| ID | Поведение |\n|---|---|\n| UNIT-NORM-01 | обрезает |\n| UNIT-JS-BO-01 | 1000 |\nПара — UNIT-PROSE-01.\n",
  },
  { path: "docs/test-plans/smoke.md", text: "| SMOKE-01 | Открыть публичную часть | 200 |\n" },
  { path: "docs/test-plans/e2e.md", text: "### E2E-01. Путь комментария\n### E2E-FIX-01. Фабрики\n" },
  {
    path: "docs/test-plans/regression.md",
    text: "## Гость (REG-G)\n| G-13 | имя |\n| G-13a | имя |\n## Админ\n| A-08a | вход |\n| WS-01 | токен |\n",
  },
  { path: "docs/test-plans/README.md", text: "| P1 | Админ входит | SMOKE-02 |\n" },
];

const context = { planIds: extractPlanIds(PLAN) };

describe("test-ids", () => {
  it("из плана берутся ID из первой ячейки таблиц и заголовков; короткие ID regression получают префикс REG-", () => {
    expect([...extractPlanIds(PLAN)].toSorted()).toStrictEqual([
      "E2E-01",
      "E2E-FIX-01",
      "REG-A-08a",
      "REG-G-13",
      "REG-G-13a",
      "REG-WS-01",
      "SMOKE-01",
      "UNIT-JS-BO-01",
      "UNIT-NORM-01",
    ]);
  });

  it.each([
    ["Vitest", "backend/web/js/__tests__/a.test.js", "\nit('UNIT-JS-BO-01: 1000, 2000, 4000', () => {});\n"],
    ["Playwright", "e2e/tests/smoke/a.spec.ts", "\ntest('SMOKE-01: публичная часть открывается', async () => {});\n"],
    [
      "Codeception unit",
      "common/tests/unit/NormalizerTest.php",
      "<?php\nclass NormalizerTest {\n    #[TestDox('UNIT-NORM-01: обрезает пробелы')]\n    public function testTrims(): void {}\n}\n",
    ],
    [
      "Codeception Cest",
      "frontend/tests/regression/FeedCest.php",
      "<?php\nclass FeedCest {\n    /** REG-G-13a: имя «Ян» сохраняется */\n    public function savesName(RegressionTester $I): void {}\n    public function _before(RegressionTester $I): void {}\n}\n",
    ],
    [
      "тест бага",
      "frontend/tests/regression/BugCest.php",
      "<?php\n/** REG-BUG-007: см. .scratch/bugs/007.md */\npublic function bug(RegressionTester $I): void {}\n",
    ],
    ["describe без ID", "backend/web/js/__tests__/a.test.js", "\ndescribe('backoff', () => {});\n"],
  ])("%s с ID из плана → нарушений нет", (_case, path, text) => {
    expect(testIds.check({ path, text }, context)).toStrictEqual([]);
  });

  it.each([
    [
      "ID из прозы, а не из таблицы",
      "backend/web/js/__tests__/a.test.js",
      "\nit('UNIT-PROSE-01: …', () => {});\n",
      "UNIT-PROSE-01",
    ],
    ["опечатка в суффиксе", "frontend/tests/regression/FeedCest.php", "<?php\n/** REG-G-13b: … */\n", "REG-G-13b"],
  ])("%s → нарушение «ID нет в плане»", (_case, path, text, id) => {
    expect(testIds.check({ path, text }, context)).toStrictEqual([
      { line: 2, message: `${id} нет в docs/test-plans/: добавьте сценарий в план или исправьте ID` },
    ]);
  });

  it.each([
    ["Vitest без ID", "backend/web/js/__tests__/a.test.js", "\nit('считает задержку', () => {});\n"],
    ["Playwright с ID не в начале", "e2e/tests/smoke/a.spec.ts", "\ntest('открывается (SMOKE-01)', async () => {});\n"],
    [
      "PHP-тест без ID",
      "common/tests/unit/ATest.php",
      "<?php\nclass ATest {\n    public function testTrims(): void {}\n}\n",
    ],
    [
      "Cest с ID у соседнего метода",
      "frontend/tests/regression/FeedCest.php",
      "<?php\n/** REG-G-13: … */\npublic function a(RegressionTester $I): void {}\npublic function b(RegressionTester $I): void {}\n",
    ],
  ])("%s → нарушение «тест без ID»", (_case, path, text) => {
    const violations = testIds.check({ path, text }, context);

    expect(violations.map(({ message }) => message)).toStrictEqual([
      "тест без ID из тест-плана: имя начинается с ID (`UNIT-VAL-01: …`), в PHP ID стоит в TestDox или docblock метода",
    ]);
  });

  it("применяется к тестам продукта: тесты проверок не описаны в тест-планах", () => {
    expect(testIds.files).toStrictEqual(PRODUCT_TEST_FILES);
  });

  it("реализован тот ID, с которого начинается имя теста: строка JS, TestDox, docblock Cest", () => {
    const tests = [
      { path: "backend/web/js/__tests__/a.test.js", text: "\nit.each([1])('UNIT-JS-BO-01: %s', () => {});\n" },
      { path: "e2e/tests/smoke/a.spec.ts", text: '\ntest("SMOKE-01: открывается", async () => {});\n' },
      {
        path: "common/tests/unit/NTest.php",
        text: "<?php\n    #[TestDox('UNIT-NORM-01: обрезает')]\n    public function testTrims(): void {}\n",
      },
      {
        path: "frontend/tests/regression/FCest.php",
        text:
          "<?php\n    /** REG-G-13: имя */\n    public function name(RegressionTester $I): void {}\n" +
          "    /**\n     * REG-G-13a: имя\n     */\n    public function nameLong(RegressionTester $I): void {}\n",
      },
    ];

    expect([...implementedIds(tests)].toSorted()).toStrictEqual([
      "REG-G-13",
      "REG-G-13a",
      "SMOKE-01",
      "UNIT-JS-BO-01",
      "UNIT-NORM-01",
    ]);
  });

  it("упоминание ID в комментарии или в середине строки не реализует его", () => {
    const tests = [
      {
        path: "backend/web/js/__tests__/a.test.js",
        text: "\n// UNIT-JS-BO-01: см. план\nit('UNIT-NORM-01: как в SMOKE-01: …', () => {});\n",
      },
    ];

    expect([...implementedIds(tests)]).toStrictEqual(["UNIT-NORM-01"]);
  });

  it("упоминание ID в строке комментария, в закомментированном тесте или в docblock не перед методом не реализует его", () => {
    const tests = [
      {
        path: "e2e/tests/smoke/a.spec.ts",
        text: '\n/**\n * SMOKE-01: выключен\n */\n// TODO вернуть "SMOKE-02: …"\n// test("E2E-01: путь", async () => {});\n',
      },
      {
        path: "frontend/tests/regression/FCest.php",
        text: "<?php\n    /** REG-G-13: константа */\n    const LIMIT = 1;\n    // 'REG-G-13a: выключен'\n    public function helper(): void {}\n    /** Имя без ID, REG-G-13 в середине */\n    public function plain(RegressionTester $I): void {}\n",
      },
      {
        path: "backend/web/js/__tests__/a.test.js",
        text: '\nit(\n  "UNIT-JS-BO-01: имя на второй строке",\n  () => {},\n);\n',
      },
    ];

    expect([...implementedIds(tests)]).toStrictEqual(["UNIT-JS-BO-01"]);
  });

  it("сценарий плана без теста → нарушение с файлом и строкой плана", () => {
    const tests = [
      { path: "e2e/tests/smoke/a.spec.ts", text: '\ntest("SMOKE-01: открывается", async () => {});\n' },
      {
        path: "frontend/tests/regression/FCest.php",
        text: "<?php\n    /** REG-G-13: имя */\n    public function name(RegressionTester $I): void {}\n",
      },
    ];

    expect(unimplementedPlanIds(PLAN, tests)).toStrictEqual([
      {
        path: "docs/test-plans/unit.md",
        line: 3,
        message: "UNIT-NORM-01 из плана не реализован: ни одно имя теста не начинается с этого ID",
      },
      {
        path: "docs/test-plans/unit.md",
        line: 4,
        message: "UNIT-JS-BO-01 из плана не реализован: ни одно имя теста не начинается с этого ID",
      },
      {
        path: "docs/test-plans/e2e.md",
        line: 1,
        message: "E2E-01 из плана не реализован: ни одно имя теста не начинается с этого ID",
      },
      {
        path: "docs/test-plans/e2e.md",
        line: 2,
        message: "E2E-FIX-01 из плана не реализован: ни одно имя теста не начинается с этого ID",
      },
      {
        path: "docs/test-plans/regression.md",
        line: 3,
        message: "REG-G-13a из плана не реализован: ни одно имя теста не начинается с этого ID",
      },
      {
        path: "docs/test-plans/regression.md",
        line: 5,
        message: "REG-A-08a из плана не реализован: ни одно имя теста не начинается с этого ID",
      },
      {
        path: "docs/test-plans/regression.md",
        line: 6,
        message: "REG-WS-01 из плана не реализован: ни одно имя теста не начинается с этого ID",
      },
    ]);
  });
});
