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
 * @property {string} uuid имя файла результата: `<uuid>-result.json`
 * @property {number} start
 * @property {number} stop
 * @property {string} historyId
 * @property {Parameter[]} parameters
 * @property {Label[]} labels
 * @typedef {{ suite: string, total: number, passed: number, failed: number, other: number }} SuiteSummary
 * @typedef {object} RunSummary
 * @property {string | undefined} startedAt начало первого теста
 * @property {number | undefined} durationMs от начала первого теста до конца последнего
 * @property {SuiteSummary[]} suites наборы продукта в порядке пирамиды
 * @property {SuiteSummary} tools тесты своих инструментов
 */

import { LEADING_ID, SUITES } from "../checks/test-names.js";
import { ReportError } from "./report-error.js";

const GROUP_LABELS = new Set(["parentSuite", "suite", "subSuite"]);
const TOOLS_GROUP = "Тесты инструментов";
const DATASET = "набор данных";
/** Поле fullName у allure-vitest: `<проект>:<файл>#<имя>`; тесты своих инструментов лежат в tools/. */
const TOOLS_TEST = /^[^:#]*:tools\//u;
const FAILED = new Set(["failed", "broken"]);

/**
 * Полей не проверяет: результат пишет адаптер Allure, проверяется только, что это объект результата с метками.
 *
 * @param {unknown} value
 * @returns {value is AllureResult}
 */
const isResult = (value) =>
  typeof value === "object" && value !== null && "labels" in value && Array.isArray(value.labels);

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
    throw new ReportError(`${file}: не результат Allure`);
  }

  return value;
}

/**
 * Группы «набор → ID»; тесты своих инструментов — отдельная группа, внутри прежний набор адаптера.
 *
 * @param {AllureResult} result
 * @param {string} name имя теста из плана
 * @returns {Label[]}
 */
const groupLabels = ({ fullName, labels }, name) => {
  const [, id, prefix] = LEADING_ID.exec(name) ?? [];
  const suite = SUITES.find(([, idPrefix]) => idPrefix === prefix)?.[0];
  const kept = labels.filter((label) => !GROUP_LABELS.has(label.name));
  const adapterGroup = labels.find((label) => label.name === "parentSuite");

  if (id !== undefined && suite !== undefined) {
    return [...kept, { name: "parentSuite", value: suite }, { name: "suite", value: id }];
  }
  if (!TOOLS_TEST.test(fullName)) {
    throw new ReportError(`${fullName}: у теста продукта нет ID в начале имени`);
  }

  return [
    ...kept,
    { name: "parentSuite", value: TOOLS_GROUP },
    ...(adapterGroup === undefined ? [] : [{ name: "suite", value: adapterGroup.value }]),
  ];
};

/**
 * Один прогон (job): имя из плана, группы и номер набора data provider. Наборы data provider в Codeception
 * приходят без параметров и с одним historyId: Allure посчитал бы их повторами одного теста.
 *
 * @param {readonly AllureResult[]} results
 * @param {ReadonlyMap<string, string>} titles
 * @returns {AllureResult[]}
 */
const prepareRun = (results, titles) => {
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
      labels: groupLabels(source, name),
      ...(dataset
        ? { historyId: `${source.historyId}#${number}`, parameters: [{ name: DATASET, value: String(number) }] }
        : {}),
    };
  });
};

/**
 * Результаты прогонов (по одному на job CI) к отчёту. Тест, который шёл в нескольких прогонах (smoke идёт в каждом
 * job со стендом), остаётся одним результатом: упавший экземпляр, если он есть, иначе первый.
 *
 * @param {readonly (readonly AllureResult[])[]} runs
 * @param {ReadonlyMap<string, string>} titles имена PHP-тестов, см. phpTestTitles
 * @returns {AllureResult[]}
 */
export function prepareResults(runs, titles) {
  /** @type {Map<string, AllureResult>} */
  const kept = new Map();
  for (const result of runs.flatMap((run) => prepareRun(run, titles))) {
    const previous = kept.get(result.historyId);
    if (previous === undefined || (FAILED.has(result.status) && !FAILED.has(previous.status))) {
      kept.set(result.historyId, result);
    }
  }

  return [...kept.values()];
}

/**
 * @param {string} suite
 * @param {readonly AllureResult[]} own
 * @returns {SuiteSummary}
 */
const summarize = (suite, own) => {
  const passed = own.filter(({ status }) => status === "passed").length;
  const failed = own.filter(({ status }) => FAILED.has(status)).length;

  return { suite, total: own.length, passed, failed, other: own.length - passed - failed };
};

/**
 * @param {readonly AllureResult[]} results
 * @param {string} group
 * @returns {AllureResult[]}
 */
const inGroup = (results, group) =>
  results.filter(({ labels }) => labels.some((label) => label.name === "parentSuite" && label.value === group));

/**
 * Итог по наборам для раздела 1 страницы.
 *
 * @param {readonly AllureResult[]} results подготовленные prepareResults
 * @returns {RunSummary}
 */
export function summarizeRun(results) {
  const start = results.length === 0 ? undefined : Math.min(...results.map((result) => result.start));
  const stop = Math.max(...results.map((result) => result.stop));

  return {
    startedAt: start === undefined ? undefined : new Date(start).toISOString(),
    durationMs: start === undefined ? undefined : stop - start,
    suites: SUITES.map(([suite]) => summarize(suite, inGroup(results, suite))),
    tools: summarize(TOOLS_GROUP, inGroup(results, TOOLS_GROUP)),
  };
}
