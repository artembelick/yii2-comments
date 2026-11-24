// @ts-check
import { fileURLToPath } from "node:url";

import { describe, expect, it } from "vitest";

import { run } from "../cli.js";

/**
 * @param {string} name
 * @returns {string}
 */
const fixture = (name) => fileURLToPath(new URL(`fixtures/${name}`, import.meta.url));

/**
 * Запускает CLI на дереве-фикстуре и собирает вывод.
 *
 * @param {string} tree
 * @param {string[]} argv
 * @returns {Promise<{ code: number, output: string }>}
 */
const runOn = async (tree, argv) => {
  /** @type {string[]} */
  const chunks = [];
  const code = await run({
    root: fixture(tree),
    argv,
    write: (chunk) => {
      chunks.push(chunk);
    },
  });

  return { code, output: chunks.join("") };
};

describe("tools/checks CLI", () => {
  it("чистое дерево → код 0", async () => {
    await expect(runOn("clean", [])).resolves.toStrictEqual({ code: 0, output: "tools/checks: нарушений нет\n" });
  });

  it("нарушение → код 1, в выводе правило, путь и строка", async () => {
    await expect(runOn("dirty", [])).resolves.toStrictEqual({
      code: 1,
      output:
        "no-skip-only: e2e/tests/smoke/public.spec.ts:2 «test.only» запрещён: тест либо работает и проходит, либо удаляется\n" +
        "entrypoint-limits: ws/runtime/server.php:3 «if» запрещён в entrypoint: логика уходит в классы и модули под unit-тестами\n" +
        "tools/checks: нарушений: 2\n",
    });
  });

  it("glob правила не нашёл ни одного файла → код 1: правило, которое ничего не проверяет, не должно молча проходить", async () => {
    const { code, output } = await runOn("empty", []);

    expect({ code, firstLine: output.split("\n")[0] }).toStrictEqual({
      code: 1,
      firstLine: "no-inline-disable: ни одного файла по **/*.{php,js,mjs,cjs,ts} — правило ничего не проверяет",
    });
  });

  it("--allow-empty разрешает пустые glob'ы (только для каркаса, пока тестов ещё нет)", async () => {
    await expect(runOn("empty", ["--allow-empty"])).resolves.toStrictEqual({
      code: 0,
      output: "tools/checks: нарушений нет\n",
    });
  });

  it.each([
    ["covered.xml", { code: 0, output: "tools/checks coverage: покрытие PHP 100%\n" }],
    [
      "uncovered.xml",
      {
        code: 1,
        output: "common/web/PlaceholderController.php: строки 0/1, методы 0/1 — порог 100%\n",
      },
    ],
    ["missing.xml", { code: 1, output: "нет отчёта reports/missing.xml: unit-тесты PHP не запускались?\n" }],
  ])("coverage %s", async (file, expected) => {
    await expect(runOn(".", ["coverage", `reports/${file}`, "--strip=/app/"])).resolves.toStrictEqual(expected);
  });

  it.each([
    [
      "junit-green.xml",
      { code: 0, output: "tools/checks junit: тестов без проверок, пропусков и предупреждений нет\n" },
    ],
    ["junit-useless.xml", { code: 1, output: "NormalizerTest::testTrims: тест без проверок (ПР-5)\n" }],
    ["missing.xml", { code: 1, output: "нет отчёта reports/missing.xml: unit-тесты PHP не запускались?\n" }],
  ])("junit %s", async (file, expected) => {
    await expect(runOn(".", ["junit", `reports/${file}`])).resolves.toStrictEqual(expected);
  });

  it.each([
    ["неизвестная команда", ["lnt"]],
    ["coverage без префикса", ["coverage", "reports/covered.xml"]],
    ["junit без файла", ["junit"]],
  ])("%s → код 2 и подсказка", async (_case, argv) => {
    await expect(runOn("clean", argv)).resolves.toStrictEqual({
      code: 2,
      output:
        "использование: node tools/checks/bin.js [--allow-empty] | coverage <clover.xml> --strip=<префикс пути в отчёте> | junit <junit.xml>\n",
    });
  });
});
