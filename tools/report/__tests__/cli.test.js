// @ts-check
import { mkdtemp, readdir, readFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { describe, expect, it, onTestFinished } from "vitest";

import { run } from "../cli.js";
import { parseResult } from "../results.js";

const SHA = "0123456789abcdef0123456789abcdef01234567";

/**
 * @param {string} name
 * @returns {string}
 */
const fixture = (name) => fileURLToPath(new URL(`fixtures/${name}`, import.meta.url));

/**
 * Запускает сборку на дереве-фикстуре; подготовленные результаты и страница пишутся во временный каталог.
 *
 * @param {string} tree
 * @param {string} repo
 * @param {string} [results] каталог Allure-результатов в дереве
 * @returns {Promise<{ code: number, output: string, out: string }>}
 */
const buildOn = async (tree, repo, results = "reports/allure-results") => {
  const out = await mkdtemp(path.join(tmpdir(), "report-"));
  // Временный каталог удаляется после теста (rules.md, ИЗ-5).
  onTestFinished(async () => rm(out, { recursive: true, force: true }));
  /** @type {string[]} */
  const chunks = [];
  const code = await run({
    root: fixture(tree),
    argv: [
      `--results=${results}`,
      "--php-coverage=reports/coverage-php.xml",
      "--js-coverage=reports/coverage-js/coverage-summary.json",
      `--prepared=${out}/prepared`,
      `--page=${out}/index.html`,
      `--repo=${repo}`,
      `--sha=${SHA}`,
    ],
    write: (chunk) => {
      chunks.push(chunk);
    },
  });

  return { code, output: chunks.join(""), out };
};

describe("tools/report CLI", () => {
  it("собирает страницу из шаблона: цифры из результатов (повтор теста в другом прогоне — один раз), ссылки по SHA; адрес репозитория из git remote без .git", async () => {
    const { code, output, out } = await buildOn("repo", "git@github.com:o/r.git");
    const blob = `https://github.com/o/r/blob/${SHA}`;

    expect({ code, output, page: await readFile(path.join(out, "index.html"), "utf8") }).toStrictEqual({
      code: 0,
      output: `tools/report: результатов 2, страница ${out}/index.html\n`,
      page:
        "<h2>Итог</h2>\n" +
        `<p>Коммит <a href="https://github.com/o/r/commit/${SHA}"><code>0123456</code></a>, прогон начат 2026-11-25 09:50 UTC и шёл 1 мин. ` +
        '<a href="allure/index.html">Отчёт Allure</a></p>\n' +
        "<table><thead><tr><th>Набор</th><th>Всего</th><th>Прошло</th><th>Упало</th><th>Прочее</th></tr></thead><tbody>\n" +
        '<tr class="failed"><td>unit</td><td>2</td><td>1</td><td>1</td><td>0</td></tr>\n' +
        '<tr class="missing"><td>smoke</td><td colspan="4">не запускался</td></tr>\n' +
        '<tr class="missing"><td>regression</td><td colspan="4">не запускался</td></tr>\n' +
        '<tr class="missing"><td>e2e</td><td colspan="4">не запускался</td></tr>\n' +
        '<tr class="failed total"><td>Всего</td><td>2</td><td>1</td><td>1</td><td>0</td></tr>\n' +
        '<tr class="tools"><td>Тесты инструментов</td><td>0</td><td>0</td><td>0</td><td>0</td></tr>\n' +
        "</tbody></table>\n" +
        `<p><a href="${blob}/docs/adr/0001-double-loop-tdd.md"><code>docs/adr/0001-double-loop-tdd.md</code></a> ` +
        `<a href="${blob}/common/tests/unit/NormalizerTest.php">UNIT-NORM-01</a></p>\n`,
    });
  });

  it("подготовленные результаты: имя PHP-теста из TestDox; подкаталог — отдельный прогон, его повтор не пишется; контейнеры и вложения всех прогонов копируются как есть", async () => {
    const { out } = await buildOn("repo", "https://github.com/o/r");
    const prepared = path.join(out, "prepared");
    const php = parseResult(await readFile(path.join(prepared, "php-result.json"), "utf8"), "php-result.json");
    const files = await readdir(prepared);

    expect({
      files: files.toSorted(),
      name: php.name,
      attachment: await readFile(path.join(prepared, "a-attachment.txt"), "utf8"),
    }).toStrictEqual({
      files: ["a-attachment.txt", "b-attachment.txt", "c-container.json", "js-result.json", "php-result.json"],
      name: "UNIT-NORM-01: обрезает пробелы",
      attachment: "trace",
    });
  });

  it("битая ссылка в шаблоне → код 1 и причина, страница не пишется", async () => {
    const { code, output, out } = await buildOn("broken", "https://github.com/o/r");

    expect({ code, output, files: await readdir(out) }).toStrictEqual({
      code: 1,
      output: "tools/report: {{test:UNIT-X-01}}: ни одно имя теста не начинается с этого ID\n",
      files: ["prepared"],
    });
  });

  it("тест продукта без ID в имени → код 1 и причина", async () => {
    const { code, output } = await buildOn("broken", "https://github.com/o/r", "reports/no-id");

    expect({ code, output }).toStrictEqual({
      code: 1,
      output: `tools/report: ${String.raw`frontend\tests\regression\FeedCest::feedOpens`}: у теста продукта нет ID в начале имени\n`,
    });
  });

  it("сбой чтения (нет каталога результатов) → ошибка как есть, а не «битая ссылка» с кодом 1", async () => {
    await expect(buildOn("repo", "https://github.com/o/r", "reports/missing")).rejects.toThrow("ENOENT");
  });

  it("без обязательных параметров → код 2 и подсказка", async () => {
    /** @type {string[]} */
    const chunks = [];

    const code = await run({
      root: fixture("repo"),
      argv: ["--results=reports", "лишний-аргумент"],
      write: (chunk) => {
        chunks.push(chunk);
      },
    });

    expect({ code, output: chunks.join("") }).toStrictEqual({
      code: 2,
      output:
        "использование: node tools/report/bin.js --results=<allure-results> --php-coverage=<clover.xml> " +
        "--js-coverage=<coverage-summary.json> --prepared=<каталог> --page=<index.html> --repo=<адрес репозитория> --sha=<коммит>\n",
    });
  });
});
