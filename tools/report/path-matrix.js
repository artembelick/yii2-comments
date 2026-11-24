// @ts-check
/**
 * Матрица «Путь комментария» из docs/test-plans/README.md для раздела 3 страницы: шаг P1–P13 → ID тестов.
 * Ячейки записаны коротко (README плана, под матрицей): в колонке regression без `REG-`, в unit без `UNIT-`,
 * `A-01…A-13` — диапазон по порядку плана, `LOGIN` — все ID семейства.
 */

/** @typedef {{ label: string, ids: string[] }} MatrixToken */
/** @typedef {{ step: string, what: string, cells: MatrixToken[][] }} MatrixRow */

const ROW = /^\|\s*(P\d+)\s*\|(.*)\|\s*$/gmu;
/** Префиксы колонок smoke, e2e, regression, unit. */
const COLUMN_PREFIXES = ["", "", "REG-", "UNIT-"];
const RANGE = "…";

/**
 * @param {string} token
 * @param {string} prefix
 * @returns {string}
 */
const withPrefix = (token, prefix) => (token.startsWith(prefix) ? token : `${prefix}${token}`);

/**
 * @param {string} label
 * @param {readonly string[]} planIds
 * @param {string} prefix
 * @returns {string[]}
 */
const expand = (label, planIds, prefix) => {
  const [first = "", last] = label.split(RANGE);

  if (last !== undefined) {
    const from = planIds.indexOf(first);
    const to = planIds.indexOf(withPrefix(last, prefix));

    return from === -1 || to < from ? [] : planIds.slice(from, to + 1);
  }

  return planIds.includes(label) ? [label] : planIds.filter((id) => id.startsWith(`${label}-`));
};

/**
 * @param {string} cell
 * @param {readonly string[]} planIds
 * @param {string} prefix
 * @returns {MatrixToken[]}
 */
const parseCell = (cell, planIds, prefix) =>
  cell
    .split(",")
    .map((token) => token.trim())
    .filter((token) => token !== "")
    .map((token) => {
      const label = withPrefix(token, prefix);
      const ids = expand(label, planIds, prefix);

      if (ids.length === 0) {
        throw new Error(`${label} из матрицы пути нет в docs/test-plans/`);
      }

      return { label, ids };
    });

/**
 * @param {string} readme текст docs/test-plans/README.md
 * @param {readonly string[]} planIds ID плана в порядке объявления
 * @returns {MatrixRow[]}
 */
export function pathMatrix(readme, planIds) {
  return [...readme.matchAll(ROW)].map(([, step, rest]) => {
    const [what = "", ...cells] = String(rest).split("|");

    return {
      step: String(step),
      what: what.trim(),
      cells: COLUMN_PREFIXES.map((prefix, index) => parseCell(cells[index] ?? "", planIds, prefix)),
    };
  });
}
