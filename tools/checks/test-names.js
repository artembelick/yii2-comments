// @ts-check
/**
 * Имена тестов и ID в них: один разбор для сверки ID (rules/test-ids.js) и сборки отчёта (tools/report).
 * Имя теста — строка `test`/`it` в JS, TestDox или первая строка docblock метода в PHP (rules.md, ЧТ-1).
 */

import { maskSource, pair } from "./source.js";

/** @typedef {import("./source.js").SourceFile} SourceFile */

/**
 * Наборы в порядке пирамиды и префиксы их ID (docs/test-plans/README.md).
 *
 * @type {readonly (readonly [string, string])[]}
 */
export const SUITES = [
  ["unit", "UNIT"],
  ["smoke", "SMOKE"],
  ["regression", "REG"],
  ["e2e", "E2E"],
];

/** Полный ID сценария: префикс набора и части через дефис, `REG-G-13a`. */
export const FULL_ID = String.raw`(?:${SUITES.map(([, prefix]) => prefix).join("|")})(?:-[A-Z0-9]+)+[a-z]?`;
/** ID в начале имени теста; группа 1 — ID, группа 2 — префикс набора. */
export const LEADING_ID = new RegExp(
  String.raw`^((${SUITES.map(([, prefix]) => prefix).join("|")})(?:-[A-Z0-9]+)+[a-z]?):`,
  "u",
);

const NAMESPACE = /^namespace\s+([\w\\]+);/mu;
const CLASS = /\bclass\s+(\w+)/u;
const METHOD = /public function (?!_)(\w+)\s*\(/gu;
/** Атрибуты PHP прямо перед методом. */
const ATTRIBUTES = String.raw`(?:#\[[^\n]*\]\s*)*$`;
/** TestDox в атрибутах метода. */
const TEST_DOX = new RegExp(String.raw`#\[TestDox\('([^']+)'\)\]\s*${ATTRIBUTES}`, "u");
/** Первая строка docblock метода, если это не тег: однострочный docblock или первая строка после `/**`. */
const DOC_TITLE = new RegExp(
  String.raw`\/\*\*[\s*]*([^\s*@](?:(?!\*\/)[^\n])*?)\s*(?=\*\/|\n)(?:(?!\*\/)[\s\S])*\*\/\s*${ATTRIBUTES}`,
  "u",
);
/** Первый аргумент-строка `test(`, `it(`, `test.only(` или `it.each(…)(` — имя теста JS. */
const JS_TEST_NAME = new RegExp(String.raw`(?:(?<![.\w])(?:test|it)(?:\.\w+)*|\))\(\s*['"\x60](${FULL_ID}):`, "gu");

/**
 * Имена PHP-тестов из исходников: TestDox или первая строка docblock метода.
 * Allure-codeception пишет имя метода, а имя теста с ID есть только в исходнике.
 *
 * @param {readonly SourceFile[]} files
 * @returns {Map<string, string>} `namespace\Класс::метод` → имя теста
 */
export function phpTestTitles(files) {
  return new Map(
    files.flatMap(({ text }) => {
      const prefix = `${NAMESPACE.exec(text)?.[1] ?? ""}\\${String(CLASS.exec(text)?.[1])}::`;

      return [...text.matchAll(METHOD)].flatMap((match) => {
        const before = text.slice(0, match.index);
        const title = TEST_DOX.exec(before)?.[1] ?? DOC_TITLE.exec(before)?.[1];

        return title === undefined ? [] : [pair(`${prefix}${String(match[1])}`, title)];
      });
    }),
  );
}

/**
 * ID, с которых начинаются имена тестов файла. Комментарии и docblock не перед методом имени теста не дают.
 *
 * @param {SourceFile} file
 * @returns {string[]}
 */
export function testNameIds(file) {
  if (file.path.endsWith(".php")) {
    return [...phpTestTitles([file]).values()].flatMap((title) => LEADING_ID.exec(title)?.[1] ?? []);
  }

  return [...maskSource(file, { comments: true, strings: false }).matchAll(JS_TEST_NAME)].map(([, id]) => String(id));
}
