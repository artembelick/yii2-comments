// @ts-check
import { access, copyFile, mkdir, readdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";

import { filesOf, listFiles } from "../checks/cli.js";
import { extractPlanIds, implementedIds, testIds } from "../checks/rules/test-ids.js";
import { PHP_TEST_FILES } from "../checks/test-locations.js";
import { jsCoverage, phpCoverage } from "./coverage.js";
import { renderPage } from "./page.js";
import { pathMatrix } from "./path-matrix.js";
import { parseResult, phpTestTitles, prepareResults, summarizeRun } from "./results.js";

/** @typedef {import("./results.js").AllureResult} AllureResult */
/** @typedef {import("./results.js").SourceFile} SourceFile */
/** @typedef {{ root: string, argv: readonly string[], write: (chunk: string) => void }} CliOptions */

const OPTIONS = ["results", "php-coverage", "js-coverage", "prepared", "page", "repo", "sha"];
const OPTION = /^--([\w-]+)=(.+)$/su;
const USAGE =
  "использование: node tools/report/bin.js --results=<allure-results> --php-coverage=<clover.xml> " +
  "--js-coverage=<coverage-summary.json> --prepared=<каталог> --page=<index.html> --repo=<адрес репозитория> --sha=<коммит>\n";
const TEMPLATE = "tools/report/page.html";
const PLAN_DIR = "docs/test-plans";
const RESULT_SUFFIX = "-result.json";
export const EXIT_OK = 0;
export const EXIT_BROKEN = 1;
export const EXIT_USAGE = 2;

/**
 * @param {string} key
 * @param {string} value
 * @returns {[string, string]}
 */
const pair = (key, value) => [key, value];

/**
 * `--имя=значение` → имя и значение; прочие аргументы не учитываются.
 *
 * @param {readonly string[]} argv
 * @returns {Map<string, string>}
 */
const parseOptions = (argv) =>
  new Map(
    argv.flatMap((arg) => {
      const option = OPTION.exec(arg);

      return option === null ? [] : [pair(String(option[1]), String(option[2]))];
    }),
  );

/**
 * Адрес репозитория на GitHub из git remote: `git@github.com:o/r.git` и `https://github.com/o/r.git` → `https://github.com/o/r`.
 *
 * @param {string} remote
 * @returns {string}
 */
const repoUrl = (remote) => remote.replace(/^git@([^:]+):/u, "https://$1/").replace(/\.git$/u, "");

/**
 * @param {string} file
 * @returns {Promise<boolean>}
 */
const exists = async (file) => {
  try {
    await access(file);

    return true;
  } catch {
    return false;
  }
};

/**
 * Отчёт, которого может не быть (unit упал раньше, чем записал покрытие).
 *
 * @param {string} file
 * @returns {Promise<string | undefined>}
 */
const readOptional = async (file) => ((await exists(file)) ? readFile(file, "utf8") : undefined);

/**
 * Allure-результаты: `*-result.json` готовятся к отчёту, контейнеры и вложения копируются как есть.
 *
 * @param {string} from
 * @param {string} to
 * @param {ReadonlyMap<string, string>} titles
 * @returns {Promise<AllureResult[]>}
 */
const prepareDirectory = async (from, to, titles) => {
  const names = await readdir(from);
  const resultNames = names.filter((name) => name.endsWith(RESULT_SUFFIX));
  const texts = await Promise.all(resultNames.map(async (name) => readFile(path.join(from, name), "utf8")));
  const prepared = prepareResults(
    texts.map((text, index) => parseResult(text, String(resultNames[index]))),
    titles,
  );
  const copied = names.filter((name) => !name.endsWith(RESULT_SUFFIX));

  await mkdir(to, { recursive: true });
  await Promise.all([
    ...copied.map(async (name) => copyFile(path.join(from, name), path.join(to, name))),
    ...prepared.map(async (result, index) =>
      writeFile(path.join(to, String(resultNames[index])), JSON.stringify(result)),
    ),
  ]);

  return prepared;
};

/**
 * ID → файл теста, имя которого начинается с ID (первый по алфавиту).
 *
 * @param {readonly SourceFile[]} tests
 * @returns {Map<string, string>}
 */
const testFilesById = (tests) =>
  new Map(tests.flatMap((file) => [...implementedIds([file])].map((id) => pair(id, file.path))).toReversed());

/**
 * @param {string} root
 * @param {readonly string[]} files
 * @returns {Promise<SourceFile[]>}
 */
const readSources = async (root, files) =>
  Promise.all(files.map(async (file) => ({ path: file, text: await readFile(path.join(root, file), "utf8") })));

/**
 * Готовит Allure-результаты и собирает главную страницу отчёта.
 *
 * @param {CliOptions} options
 * @returns {Promise<number>} EXIT_OK, EXIT_BROKEN (битая ссылка в шаблоне) или EXIT_USAGE
 */
export async function run({ root, argv, write }) {
  const values = parseOptions(argv);
  if (OPTIONS.some((name) => !values.has(name))) {
    write(USAGE);

    return EXIT_USAGE;
  }
  /** @type {(name: string) => string} */
  const option = (name) => path.resolve(root, String(values.get(name)));
  const files = await listFiles(root);
  const plans = await readSources(
    root,
    files.filter((file) => file.startsWith(`${PLAN_DIR}/`)),
  );
  const tests = await readSources(root, filesOf(testIds, files));
  const phpTests = await readSources(root, filesOf({ files: [PHP_TEST_FILES], exclude: [] }, files));
  const results = await prepareDirectory(option("results"), option("prepared"), phpTestTitles(phpTests));
  const planIds = [...extractPlanIds(plans)];

  const template = await readFile(path.join(root, TEMPLATE), "utf8");
  const readme = await readFile(path.join(root, PLAN_DIR, "README.md"), "utf8");
  const php = phpCoverage(await readOptional(option("php-coverage")));
  const js = jsCoverage(await readOptional(option("js-coverage")));
  const repo = repoUrl(String(values.get("repo")));

  try {
    const page = renderPage(template, {
      repo,
      sha: String(values.get("sha")),
      summary: summarizeRun(results),
      php,
      js,
      planIds,
      testFiles: testFilesById(tests),
      matrix: pathMatrix(readme, planIds),
      files: new Set(files),
    });
    await writeFile(option("page"), page);
    write(`tools/report: результатов ${results.length}, страница ${option("page")}\n`);

    return EXIT_OK;
  } catch (error) {
    // Ошибки сборки страницы — Error с причиной: битая ссылка, ID матрицы вне плана.
    write(`tools/report: ${String(error).replace(/^Error: /u, "")}\n`);

    return EXIT_BROKEN;
  }
}
