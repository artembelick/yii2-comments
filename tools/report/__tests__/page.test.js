// @ts-check
import { describe, expect, it } from "vitest";

import { renderPage } from "../page.js";

/** @typedef {import("../page.js").PageContext} PageContext */

const REPO = "https://github.com/o/r";
const SHA = "0123456789abcdef0123456789abcdef01234567";

/**
 * @param {Partial<PageContext>} fields
 * @returns {PageContext}
 */
const context = (fields) => ({
  repo: REPO,
  sha: SHA,
  summary: { startedAt: undefined, suites: [] },
  php: undefined,
  js: undefined,
  planIds: [],
  testFiles: new Map(),
  matrix: [],
  files: new Set(),
  ...fields,
});

describe("страница отчёта", () => {
  it("{{run}}: коммит со ссылкой, время начала, таблица по наборам с итогом и ссылка в Allure", () => {
    const summary = {
      startedAt: "2026-11-25T09:30:00.000Z",
      suites: [
        { suite: "unit", total: 3, passed: 3, failed: 0, other: 0 },
        { suite: "e2e", total: 2, passed: 1, failed: 1, other: 0 },
      ],
    };

    expect(renderPage("{{run}}", context({ summary }))).toBe(
      `<p>Коммит <a href="${REPO}/commit/${SHA}"><code>0123456</code></a>, прогон начат 2026-11-25 09:30 UTC. ` +
        `<a href="allure/index.html">Отчёт Allure</a></p>\n` +
        "<table><thead><tr><th>Набор</th><th>Всего</th><th>Прошло</th><th>Упало</th><th>Прочее</th></tr></thead><tbody>\n" +
        "<tr><td>unit</td><td>3</td><td>3</td><td>0</td><td>0</td></tr>\n" +
        '<tr class="failed"><td>e2e</td><td>2</td><td>1</td><td>1</td><td>0</td></tr>\n' +
        '<tr class="failed total"><td>Всего</td><td>5</td><td>4</td><td>1</td><td>0</td></tr>\n' +
        "</tbody></table>",
    );
  });

  it("{{run}} без результатов → «результатов нет» вместо времени", () => {
    expect(renderPage("{{run}}", context({})).split("\n")[0]).toBe(
      `<p>Коммит <a href="${REPO}/commit/${SHA}"><code>0123456</code></a>, результатов прогона нет. ` +
        `<a href="allure/index.html">Отчёт Allure</a></p>`,
    );
  });

  it("{{coverage}}: доли с числами и ссылки на HTML-отчёты по файлам", () => {
    const php = { lines: { covered: 625, total: 625 } };
    const js = { lines: { covered: 460, total: 461 }, branches: { covered: 177, total: 177 } };

    expect(renderPage("{{coverage}}", context({ php, js }))).toBe(
      "<table><thead><tr><th>Код</th><th>Покрытие</th><th>Покрыто</th><th>Отчёт</th></tr></thead><tbody>\n" +
        '<tr><td>PHP, строки (pcov)</td><td>100%</td><td>625 из 625</td><td><a href="coverage/php/index.html">по файлам</a></td></tr>\n' +
        '<tr><td>JS, строки (v8)</td><td>99.8%</td><td>460 из 461</td><td><a href="coverage/js/index.html">по файлам</a></td></tr>\n' +
        '<tr><td>JS, ветки (v8)</td><td>100%</td><td>177 из 177</td><td><a href="coverage/js/index.html">по файлам</a></td></tr>\n' +
        "</tbody></table>",
    );
  });

  it("{{coverage}} без отчётов → «нет данных», ссылок нет", () => {
    expect(renderPage("{{coverage}}", context({})).split("\n").slice(1, 2)).toStrictEqual([
      "<tr><td>PHP, строки (pcov)</td><td>нет данных</td><td></td><td></td></tr>",
    ]);
  });

  it("{{requirements}}: доля реализованных сценариев и матрица со ссылками на файлы тестов по SHA, нереализованное выделено", () => {
    const matrix = [
      {
        step: "P1",
        what: "Админ входит, `make admin` <без входа>",
        cells: [
          [],
          [{ label: "E2E-01", ids: ["E2E-01"] }],
          [{ label: "REG-A-01…A-02", ids: ["REG-A-01", "REG-A-02"] }],
          [{ label: "UNIT-X", ids: ["UNIT-X-01"] }],
        ],
      },
    ];
    const testFiles = new Map([
      ["E2E-01", "e2e/tests/e2e/exclusive/comment-path.spec.ts"],
      ["REG-A-02", "backend/tests/regression/LoginCest.php"],
    ]);

    expect(
      renderPage("{{requirements}}", context({ matrix, testFiles, planIds: ["E2E-01", "REG-A-01", "REG-A-02"] })),
    ).toBe(
      "<p>Реализовано сценариев из <code>docs/test-plans/</code>: <strong>2 из 3 (66.7%)</strong>.</p>\n" +
        "<table><thead><tr><th>Шаг</th><th>Что происходит</th><th>smoke</th><th>e2e</th><th>regression</th><th>unit</th></tr></thead><tbody>\n" +
        "<tr><td>P1</td><td>Админ входит, <code>make admin</code> &lt;без входа&gt;</td><td></td>" +
        `<td><a href="${REPO}/blob/${SHA}/e2e/tests/e2e/exclusive/comment-path.spec.ts">E2E-01</a></td>` +
        `<td><a href="${REPO}/blob/${SHA}/backend/tests/regression/LoginCest.php">REG-A-01…A-02</a></td>` +
        '<td><span class="missing">UNIT-X</span></td></tr>\n' +
        "</tbody></table>",
    );
  });

  it("{{file:…}} и {{test:…}} → ссылки на файлы по SHA прогона, а не на main", () => {
    const files = new Set(["docs/adr/0001-double-loop-tdd.md"]);
    const testFiles = new Map([["E2E-01", "e2e/tests/e2e/exclusive/comment-path.spec.ts"]]);

    expect(
      renderPage("<li>{{file:docs/adr/0001-double-loop-tdd.md}}, {{test:E2E-01}}</li>", context({ files, testFiles })),
    ).toBe(
      `<li><a href="${REPO}/blob/${SHA}/docs/adr/0001-double-loop-tdd.md"><code>docs/adr/0001-double-loop-tdd.md</code></a>, ` +
        `<a href="${REPO}/blob/${SHA}/e2e/tests/e2e/exclusive/comment-path.spec.ts">E2E-01</a></li>`,
    );
  });

  it.each([
    ["файла нет в репозитории", "{{file:docs/adr/9999.md}}", "{{file:docs/adr/9999.md}}: файла нет в репозитории"],
    ["ID не реализован тестом", "{{test:E2E-99}}", "{{test:E2E-99}}: ни одно имя теста не начинается с этого ID"],
    ["неизвестная подстановка", "{{totals}}", "{{totals}}: неизвестная подстановка"],
    ["неизвестная ссылка", "{{adr:0001}}", "{{adr:0001}}: неизвестная подстановка"],
  ])("%s → ошибка сборки, а не битая ссылка на странице", (_case, template, message) => {
    expect(() => renderPage(template, context({}))).toThrow(message);
  });
});
