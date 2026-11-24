// @ts-check
/**
 * Allure-результаты трёх раннеров перед сборкой отчёта: имя теста из плана и группы «набор → ID».
 * Адаптеры группируют по-своему (Codeception — по namespace, Playwright — по проекту и файлу), отчёт — по пирамиде.
 */

/**
 * @typedef {{ name: string, value: string }} Label
 * @typedef {{ name: string, value: string }} Parameter
 * @typedef {object} AllureResult
 * @property {string} name
 * @property {string} fullName
 * @property {string} status passed, failed, broken, skipped или unknown
 * @property {number} start
 * @property {string} historyId
 * @property {Parameter[]} parameters
 * @property {Label[]} labels
 * @typedef {{ suite: string, total: number, passed: number, failed: number, other: number }} SuiteSummary
 * @typedef {{ startedAt: string | undefined, suites: SuiteSummary[] }} RunSummary
 * @typedef {{ path: string, text: string }} SourceFile
 */

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
const LEADING_ID = /^((UNIT|REG|SMOKE|E2E)(?:-[A-Z0-9]+)+[a-z]?):/u;
const GROUP_LABELS = new Set(["parentSuite", "suite", "subSuite"]);
const TOOLS_GROUP = "Тесты инструментов";
const DATASET = "набор данных";

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

/**
 * Полей не проверяет: результат пишет адаптер Allure, проверяется только, что это объект результата с метками.
 *
 * @param {unknown} value
 * @returns {value is AllureResult}
 */
const isResult = (value) =>
  typeof value === "object" && value !== null && "labels" in value && Array.isArray(value.labels);

/**
 * @param {string} key
 * @param {string} title
 * @returns {[string, string]}
 */
const entry = (key, title) => [key, title];

/**
 * Результат Allure из файла. Сломанный файл роняет сборку: тест не должен молча выпасть из отчёта.
 *
 * @param {string} json
 * @param {string} file имя файла для сообщения об ошибке
 * @returns {AllureResult}
 */
export function parseResult(json, file) {
  /** @type {unknown} */
  const value = JSON.parse(json);
  if (!isResult(value)) {
    throw new Error(`${file}: не результат Allure`);
  }

  return value;
}

/**
 * Имена PHP-тестов из исходников: TestDox у unit, первая строка docblock у Cest (rules.md, ЧТ-1).
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

        return title === undefined ? [] : [entry(`${prefix}${String(match[1])}`, title)];
      });
    }),
  );
}

/**
 * @param {string} name
 * @param {Label[]} labels
 * @returns {Label[]}
 */
const groupLabels = (name, labels) => {
  const [, id, prefix] = LEADING_ID.exec(name) ?? [];
  const suite = SUITES.find(([, idPrefix]) => idPrefix === prefix)?.[0];
  const kept = labels.filter((label) => !GROUP_LABELS.has(label.name));
  const adapterGroup = labels.find((label) => label.name === "parentSuite");

  return id === undefined || suite === undefined
    ? [
        ...kept,
        { name: "parentSuite", value: "unit" },
        { name: "suite", value: TOOLS_GROUP },
        ...(adapterGroup === undefined ? [] : [{ name: "subSuite", value: adapterGroup.value }]),
      ]
    : [...kept, { name: "parentSuite", value: suite }, { name: "suite", value: id }];
};

/**
 * Имя из плана и группы «набор → ID». Наборы data provider в Codeception приходят без параметров и с одним
 * historyId: Allure посчитал бы их повторами одного теста, поэтому каждый получает номер набора.
 *
 * @param {readonly AllureResult[]} results
 * @param {ReadonlyMap<string, string>} titles имена PHP-тестов, см. phpTestTitles
 * @returns {AllureResult[]}
 */
export function prepareResults(results, titles) {
  /** @type {Map<string, number>} */
  const seen = new Map();
  const duplicated = new Set(
    results.map(({ historyId }) => historyId).filter((id, index, all) => all.indexOf(id) !== index),
  );

  return results.map((source) => {
    const name = titles.get(source.fullName) ?? source.name;
    const number = (seen.get(source.historyId) ?? 0) + 1;
    seen.set(source.historyId, number);
    const dataset = duplicated.has(source.historyId) && source.parameters.length === 0;

    return {
      ...source,
      name,
      labels: groupLabels(name, source.labels),
      ...(dataset
        ? { historyId: `${source.historyId}#${number}`, parameters: [{ name: DATASET, value: String(number) }] }
        : {}),
    };
  });
}

/**
 * Итог по наборам для раздела 1 страницы.
 *
 * @param {readonly AllureResult[]} results подготовленные prepareResults
 * @returns {RunSummary}
 */
export function summarizeRun(results) {
  const starts = results.map(({ start }) => start);
  const suites = SUITES.map(([suite]) => {
    const own = results.filter(({ labels }) =>
      labels.some((label) => label.name === "parentSuite" && label.value === suite),
    );
    const passed = own.filter(({ status }) => status === "passed").length;
    const failed = own.filter(({ status }) => status === "failed" || status === "broken").length;

    return { suite, total: own.length, passed, failed, other: own.length - passed - failed };
  });

  return { startedAt: starts.length === 0 ? undefined : new Date(Math.min(...starts)).toISOString(), suites };
}
