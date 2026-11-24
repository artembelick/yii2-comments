import { findAll, lineAt } from "../source.js";
// @ts-check
import { PRODUCT_TEST_FILES } from "../test-locations.js";

/** @typedef {import("../source.js").SourceFile} SourceFile */
/** @typedef {import("../source.js").Violation} Violation */
/** @typedef {{ planIds: ReadonlySet<string> }} PlanContext */

const FULL_ID = String.raw`(?:UNIT|REG|SMOKE|E2E)(?:-[A-Z0-9]+)+[a-z]?`;
const ID_TOKEN = new RegExp(String.raw`\b${FULL_ID}\b`, "gu");
const HAS_ID = new RegExp(ID_TOKEN.source, "u");
const STARTS_WITH_ID = new RegExp(String.raw`^${FULL_ID}:`, "u");
/** Тесты на баги несут номер тикета, а не ID плана (regression.md, «Баги»). */
const BUG_ID = /^REG-BUG-\d{3}$/u;

const PLAN_ROW = new RegExp(String.raw`^(?:\|\s*(${FULL_ID})\s*\||#{1,6}\s+(${FULL_ID})\b)`, "gmu");
/** В таблицах regression ID записаны коротко: G-13 → REG-G-13. */
const SHORT_REGRESSION_ROW = /^\|\s*((?:G|A|WS)-\d+[a-z]?)\s*\|/gmu;

const JS_TEST = /(?<![.\w])(?:test|it)\(\s*['"`]([^'"`]*)/gu;
const PHP_TEST = /public function test\w*\s*\(/gu;
const CEST_TEST = /public function (?!_)\w+\s*\(\s*\w+Tester\s+\$I\b/gu;

const MISSING_ID =
  "тест без ID из тест-плана: имя начинается с ID (`UNIT-VAL-01: …`), в PHP ID стоит в TestDox или docblock метода";

/** Начало имени теста: строка JS, TestDox или docblock метода PHP (первая строка текста). */
const TEST_NAME_ID = new RegExp(String.raw`(?:["'\x60]|\/\*\*\s+|^\s*\*\s+)(${FULL_ID}):`, "gmu");

/**
 * ID сценариев, объявленные в тест-планах, с файлом и строкой объявления.
 *
 * @param {readonly SourceFile[]} planFiles
 * @returns {{ id: string, path: string, line: number }[]}
 */
const declaredPlanIds = (planFiles) =>
  planFiles.flatMap(({ path, text }) =>
    [
      ...findAll(text, PLAN_ROW, ([, row, heading]) => String(row ?? heading)),
      ...findAll(text, SHORT_REGRESSION_ROW, ([, short]) => `REG-${String(short)}`),
    ]
      .toSorted((left, right) => left.line - right.line)
      .map(({ line, message: id }) => ({ id, path, line })),
  );

/**
 * ID сценариев, объявленные в тест-планах: первая ячейка строки таблицы или заголовок.
 * Упоминания в прозе не объявляют ID.
 *
 * @param {readonly SourceFile[]} planFiles
 * @returns {Set<string>}
 */
export function extractPlanIds(planFiles) {
  return new Set(declaredPlanIds(planFiles).map(({ id }) => id));
}

/**
 * ID, с которых начинаются имена тестов. Упоминание в комментарии или в середине имени ID не реализует.
 *
 * @param {readonly SourceFile[]} testFiles
 * @returns {Set<string>}
 */
export function implementedIds(testFiles) {
  return new Set(testFiles.flatMap(({ text }) => [...text.matchAll(TEST_NAME_ID)].map(([, id]) => String(id))));
}

/**
 * Сверка в обратную сторону: ID плана, которые не реализует ни один тест.
 *
 * @param {readonly SourceFile[]} planFiles
 * @param {readonly SourceFile[]} testFiles
 * @returns {{ path: string, line: number, message: string }[]}
 */
export function unimplementedPlanIds(planFiles, testFiles) {
  const implemented = implementedIds(testFiles);

  return declaredPlanIds(planFiles)
    .filter(({ id }) => !implemented.has(id))
    .map(({ id, path, line }) => ({
      path,
      line,
      message: `${id} из плана не реализован: ни одно имя теста не начинается с этого ID`,
    }));
}

/**
 * Атрибуты и docblock перед методом: от конца предыдущей конструкции до слова public.
 *
 * @param {string} text
 * @param {number} offset
 * @returns {string}
 */
const headerBefore = (text, offset) => {
  const before = text.slice(0, offset);

  return before.slice(Math.max(before.lastIndexOf("{"), before.lastIndexOf("}"), before.lastIndexOf(";")) + 1);
};

/**
 * @param {SourceFile} file
 * @returns {Violation[]}
 */
const testsWithoutId = ({ path, text }) => {
  const jsTests = [...text.matchAll(JS_TEST)].filter(([, title]) => !STARTS_WITH_ID.test(String(title)));
  const phpTests = [...text.matchAll(path.endsWith("Cest.php") ? CEST_TEST : PHP_TEST)].filter(
    (match) => !HAS_ID.test(headerBefore(text, match.index)),
  );
  const offenders = path.endsWith(".php") ? phpTests : jsTests;

  return offenders.map((match) => ({ line: lineAt(text, match.index), message: MISSING_ID }));
};

/** ЧТ-1 и сверка ID: каждый тест несёт ID, и этот ID объявлен в docs/test-plans/. */
export const testIds = {
  id: "test-ids",
  files: PRODUCT_TEST_FILES,
  exclude: [],
  /**
   * @param {SourceFile} file
   * @param {PlanContext} context
   * @returns {Violation[]}
   */
  check(file, { planIds }) {
    const unknown = findAll(file.text, ID_TOKEN, ([id]) => id).filter(
      ({ message: id }) => !planIds.has(id) && !BUG_ID.test(id),
    );

    return [
      ...unknown.map(({ line, message: id }) => ({
        line,
        message: `${id} нет в docs/test-plans/: добавьте сценарий в план или исправьте ID`,
      })),
      ...testsWithoutId(file),
    ];
  },
};
