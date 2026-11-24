// @ts-check
/**
 * Итог покрытия для раздела 2 страницы: строки PHP (pcov, clover) и строки и ветки JS (v8, json-summary).
 * Нет отчёта — undefined: страница пишет «нет данных», цифр руками на ней нет.
 */

/** @typedef {{ covered: number, total: number }} Ratio */

/** Итоговые метрики проекта — последний `<metrics>` перед `</project>`. */
const PROJECT_METRICS = /<metrics ([^>]*)\/>\s*<\/project>/u;
/** Итог json-summary — первый ключ `total`, в нём счётчики `total` и `covered` по видам покрытия. */
const SUMMARY_TOTAL = /^\{\s*"total":\s*(\{.*?\}\s*\})/su;

/**
 * @param {string} attributes
 * @param {string} name
 * @returns {number}
 */
const metric = (attributes, name) => Number(new RegExp(String.raw`\b${name}="(\d+)"`, "u").exec(attributes)?.[1]);

/**
 * @param {string} total итог json-summary
 * @param {string} kind lines или branches
 * @returns {Ratio}
 */
const summaryRatio = (total, kind) => {
  const counters = new RegExp(String.raw`"${kind}":\s*\{\s*"total":\s*(\d+),\s*"covered":\s*(\d+)`, "u").exec(total);

  return { covered: Number(counters?.[2]), total: Number(counters?.[1]) };
};

/**
 * @param {string} [clover]
 * @returns {{ lines: Ratio } | undefined}
 */
export function phpCoverage(clover) {
  const attributes = clover === undefined ? undefined : PROJECT_METRICS.exec(clover)?.[1];

  return attributes === undefined
    ? undefined
    : { lines: { covered: metric(attributes, "coveredstatements"), total: metric(attributes, "statements") } };
}

/**
 * @param {string} [summary] содержимое coverage-summary.json
 * @returns {{ lines: Ratio, branches: Ratio } | undefined}
 */
export function jsCoverage(summary) {
  const total = summary === undefined ? undefined : SUMMARY_TOTAL.exec(summary)?.[1];

  return total === undefined
    ? undefined
    : { lines: summaryRatio(total, "lines"), branches: summaryRatio(total, "branches") };
}
