// @ts-check
/**
 * Главная страница отчёта: шаблон tools/report/page.html и подстановки из прогона.
 * Цифр руками на странице нет, ссылки на тесты, планы и ADR ведут на файлы по SHA прогона.
 * Ссылка на несуществующий файл или нереализованный ID роняет сборку: битой ссылки на странице быть не должно.
 */

/** @typedef {import("./results.js").RunSummary} RunSummary */
/** @typedef {import("./results.js").SuiteSummary} SuiteSummary */
/** @typedef {import("./coverage.js").Ratio} Ratio */
/** @typedef {import("./path-matrix.js").MatrixRow} MatrixRow */
/**
 * @typedef {object} PageContext
 * @property {string} repo адрес репозитория на GitHub без `.git`
 * @property {string} sha коммит прогона
 * @property {RunSummary} summary
 * @property {{ lines: Ratio } | undefined} php
 * @property {{ lines: Ratio, branches: Ratio } | undefined} js
 * @property {readonly string[]} planIds
 * @property {ReadonlyMap<string, string>} testFiles ID → файл теста, имя которого начинается с ID
 * @property {readonly MatrixRow[]} matrix
 * @property {ReadonlySet<string>} files файлы репозитория
 */

const PLACEHOLDER = /\{\{([^}]*)\}\}/gu;
const SHORT_SHA = 7;
/** `2026-11-25T09:30:00.000Z` → `2026-11-25 09:30`. */
const MINUTES_END = 16;
const PERCENT = 100;
const COVERAGE_HEAD = "<tr><th>Код</th><th>Покрытие</th><th>Покрыто</th><th>Отчёт</th></tr>";
const RUN_HEAD = "<tr><th>Набор</th><th>Всего</th><th>Прошло</th><th>Упало</th><th>Прочее</th></tr>";
const MATRIX_HEAD =
  "<tr><th>Шаг</th><th>Что происходит</th><th>smoke</th><th>e2e</th><th>regression</th><th>unit</th></tr>";

/** @type {Record<string, string>} */
const ENTITIES = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" };

/**
 * @param {string} text
 * @returns {string}
 */
const escape = (text) => text.replaceAll(/[&<>"]/gu, (char) => String(ENTITIES[char]));

/**
 * @param {string} head
 * @param {readonly string[]} rows
 * @returns {string}
 */
const table = (head, rows) =>
  `<table><thead>${head}</thead><tbody>\n${rows.map((row) => `${row}\n`).join("")}</tbody></table>`;

/**
 * @param {readonly (string | number)[]} cells
 * @param {string} [className]
 * @returns {string}
 */
const row = (cells, className = "") =>
  `<tr${className === "" ? "" : ` class="${className}"`}>${cells.map((cell) => `<td>${cell}</td>`).join("")}</tr>`;

/**
 * @param {number} part
 * @param {number} whole
 * @returns {string}
 */
const percent = (part, whole) => `${Number(((part / whole) * PERCENT).toFixed(1))}%`;

/**
 * @param {PageContext} context
 * @param {string} path
 * @returns {string}
 */
const blob = ({ repo, sha }, path) => `${repo}/blob/${sha}/${path}`;

/**
 * @param {SuiteSummary} summary
 * @param {string} className
 * @returns {string}
 */
const suiteRow = ({ suite, total, passed, failed, other }, className) =>
  row([suite, total, passed, failed, other], `${failed > 0 ? "failed" : ""} ${className}`.trim());

/**
 * @param {PageContext} context
 * @returns {string}
 */
const renderRun = (context) => {
  const { summary } = context;
  const when =
    summary.startedAt === undefined
      ? "результатов прогона нет"
      : `прогон начат ${summary.startedAt.replace("T", " ").slice(0, MINUTES_END)} UTC`;
  const totals = { suite: "Всего", total: 0, passed: 0, failed: 0, other: 0 };
  for (const suite of summary.suites) {
    totals.total += suite.total;
    totals.passed += suite.passed;
    totals.failed += suite.failed;
    totals.other += suite.other;
  }
  const rows = summary.suites.map((suite) => suiteRow(suite, ""));
  const totalRow = suiteRow(totals, "total");

  return (
    `<p>Коммит <a href="${context.repo}/commit/${context.sha}"><code>${context.sha.slice(0, SHORT_SHA)}</code></a>, ${when}. ` +
    `<a href="allure/index.html">Отчёт Allure</a></p>\n${table(RUN_HEAD, [...rows, totalRow])}`
  );
};

/**
 * @param {string} name
 * @param {Ratio | undefined} ratio
 * @param {string} report
 * @returns {string}
 */
const coverageRow = (name, ratio, report) =>
  ratio === undefined
    ? row([name, "нет данных", "", ""])
    : row([
        name,
        percent(ratio.covered, ratio.total),
        `${ratio.covered} из ${ratio.total}`,
        `<a href="${report}">по файлам</a>`,
      ]);

/**
 * @param {PageContext} context
 * @returns {string}
 */
const renderCoverage = ({ php, js }) =>
  table(COVERAGE_HEAD, [
    coverageRow("PHP, строки (pcov)", php?.lines, "coverage/php/index.html"),
    coverageRow("JS, строки (v8)", js?.lines, "coverage/js/index.html"),
    coverageRow("JS, ветки (v8)", js?.branches, "coverage/js/index.html"),
  ]);

/**
 * @param {PageContext} context
 * @returns {string}
 */
const renderRequirements = (context) => {
  const { planIds, testFiles, matrix } = context;
  const implemented = planIds.filter((id) => testFiles.has(id)).length;
  const rows = matrix.map(({ step, what, cells }) =>
    row([
      step,
      escape(what).replaceAll(/`([^`]+)`/gu, "<code>$1</code>"),
      ...cells.map((tokens) =>
        tokens
          .map(({ label, ids }) => {
            const file = ids.map((id) => testFiles.get(id)).find((path) => path !== undefined);

            return file === undefined
              ? `<span class="missing">${label}</span>`
              : `<a href="${blob(context, file)}">${label}</a>`;
          })
          .join(", "),
      ),
    ]),
  );

  return (
    `<p>Реализовано сценариев из <code>docs/test-plans/</code>: <strong>${implemented} из ${planIds.length} ` +
    `(${percent(implemented, planIds.length)})</strong>.</p>\n${table(MATRIX_HEAD, rows)}`
  );
};

/**
 * @param {PageContext} context
 * @param {string} path
 * @returns {string}
 */
const fileLink = (context, path) => {
  if (!context.files.has(path)) {
    throw new Error(`{{file:${path}}}: файла нет в репозитории`);
  }

  return `<a href="${blob(context, path)}"><code>${escape(path)}</code></a>`;
};

/**
 * @param {PageContext} context
 * @param {string} id
 * @returns {string}
 */
const testLink = (context, id) => {
  const file = context.testFiles.get(id);
  if (file === undefined) {
    throw new Error(`{{test:${id}}}: ни одно имя теста не начинается с этого ID`);
  }

  return `<a href="${blob(context, file)}">${escape(id)}</a>`;
};

const SECTIONS = new Map([
  ["run", renderRun],
  ["coverage", renderCoverage],
  ["requirements", renderRequirements],
]);
const LINKS = new Map([
  ["file", fileLink],
  ["test", testLink],
]);

/**
 * `{{раздел}}` или `{{ссылка:цель}}`.
 *
 * @param {string} placeholder
 * @param {PageContext} context
 * @returns {string}
 */
const substitute = (placeholder, context) => {
  const colon = placeholder.indexOf(":");
  const section = colon === -1 ? SECTIONS.get(placeholder) : undefined;
  const link = colon === -1 ? undefined : LINKS.get(placeholder.slice(0, colon));

  if (section !== undefined) {
    return section(context);
  }
  if (link !== undefined) {
    return link(context, placeholder.slice(colon + 1));
  }
  throw new Error(`{{${placeholder}}}: неизвестная подстановка`);
};

/**
 * @param {string} template
 * @param {PageContext} context
 * @returns {string}
 */
export function renderPage(template, context) {
  return template.replaceAll(PLACEHOLDER, (_match, placeholder) => substitute(String(placeholder), context));
}
