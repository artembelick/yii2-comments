// @ts-check
import { access, copyFile, mkdir, readdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";

import { filesOf, listFiles, PLAN_DIR } from "../checks/cli.js";
import { extractPlanIds, implementedIds, testIds } from "../checks/rules/test-ids.js";
import { pair } from "../checks/source.js";
import { PHP_TEST_FILES } from "../checks/test-locations.js";
import { phpTestTitles } from "../checks/test-names.js";
import { jsCoverage, phpCoverage } from "./coverage.js";
import { renderPage } from "./page.js";
import { pathMatrix } from "./path-matrix.js";
import { ReportError } from "./report-error.js";
import { parseResult, prepareResults, summarizeRun } from "./results.js";

/** @typedef {import("./results.js").AllureResult} AllureResult */
/** @typedef {import("../checks/source.js").SourceFile} SourceFile */
/** @typedef {{ root: string, argv: readonly string[], write: (chunk: string) => void }} CliOptions */
/**
 * @typedef {object} ReportOptions
 * @property {string} results каталог Allure-результатов: файлы в нём — один прогон, каждый подкаталог — ещё один (job CI)
 * @property {string} phpClover отчёт покрытия PHP (clover)
 * @property {string} jsSummary отчёт покрытия JS (json-summary)
 * @property {string} prepared
 * @property {string} page
 * @property {string} repo
 * @property {string} sha
 */

const OPTIONS = ["results", "php-coverage", "js-coverage", "prepared", "page", "repo", "sha"];
const OPTION = /^--([\w-]+)=(.+)$/su;
const USAGE =
  "использование: node tools/report/bin.js --results=<allure-results> --php-coverage=<clover.xml> " +
  "--js-coverage=<coverage-summary.json> --prepared=<каталог> --page=<index.html> --repo=<адрес репозитория> --sha=<коммит>\n";
const TEMPLATE = "tools/report/page.html";
const RESULT_SUFFIX = "-result.json";
export const EXIT_OK = 0;
export const EXIT_BROKEN = 1;
export const EXIT_USAGE = 2;

/**
 * `--имя=значение` → параметры сборки; прочие аргументы не учитываются. Без любого из параметров — undefined.
 *
 * @param {readonly string[]} argv
 * @returns {ReportOptions | undefined}
 */
const parseOptions = (argv) => {
  const values = new Map(
    argv.flatMap((arg) => {
      const option = OPTION.exec(arg);

      return option === null ? [] : [pair(String(option[1]), String(option[2]))];
    }),
  );
  const [results, phpClover, jsSummary, prepared, page, repo, sha] = OPTIONS.map((name) => values.get(name));

  return results === undefined ||
    phpClover === undefined ||
    jsSummary === undefined ||
    prepared === undefined ||
    page === undefined ||
    repo === undefined ||
    sha === undefined
    ? undefined
    : { results, phpClover, jsSummary, prepared, page, repo, sha };
};

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
 * Allure-результаты одного каталога: результаты и прочие файлы (контейнеры, вложения).
 *
 * @param {string} directory
 * @returns {Promise<{ results: AllureResult[], others: string[], runs: string[] }>}
 */
const readRun = async (directory) => {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = entries.filter((entry) => entry.isFile()).map((entry) => path.join(directory, entry.name));
  const resultFiles = files.filter((file) => file.endsWith(RESULT_SUFFIX));
  const results = await Promise.all(
    resultFiles.map(async (file) => parseResult(await readFile(file, "utf8"), path.basename(file))),
  );

  return {
    results,
    others: files.filter((file) => !file.endsWith(RESULT_SUFFIX)),
    runs: entries.filter((entry) => entry.isDirectory()).map((entry) => path.join(directory, entry.name)),
  };
};

/**
 * Allure-результаты к отчёту: файлы каталога — один прогон, каждый подкаталог — ещё один (артефакт job CI).
 * Результаты готовятся к отчёту и пишутся как `<uuid>-result.json`, контейнеры и вложения копируются как есть.
 *
 * @param {string} from
 * @param {string} to
 * @param {ReadonlyMap<string, string>} titles
 * @returns {Promise<AllureResult[]>}
 */
const prepareDirectory = async (from, to, titles) => {
  const top = await readRun(from);
  const nested = await Promise.all(top.runs.map(async (directory) => readRun(directory)));
  const runs = [top, ...nested];
  const prepared = prepareResults(
    runs.map(({ results }) => results),
    titles,
  );

  await mkdir(to, { recursive: true });
  await Promise.all([
    ...runs.flatMap(({ others }) => others).map(async (file) => copyFile(file, path.join(to, path.basename(file)))),
    ...prepared.map(async (result) =>
      writeFile(path.join(to, `${result.uuid}${RESULT_SUFFIX}`), JSON.stringify(result)),
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
 * Готовит Allure-результаты и собирает страницу. Входные данные, из которых честного отчёта не собрать, — ReportError.
 *
 * @param {string} root
 * @param {ReportOptions} options
 * @returns {Promise<{ results: AllureResult[], page: string }>}
 */
const buildReport = async (root, options) => {
  const files = await listFiles(root);
  const plans = await readSources(
    root,
    files.filter((file) => file.startsWith(`${PLAN_DIR}/`)),
  );
  const tests = await readSources(root, filesOf(testIds, files));
  const phpTests = await readSources(root, filesOf({ files: [PHP_TEST_FILES], exclude: [] }, files));
  const planIds = [...extractPlanIds(plans)];
  const template = await readFile(path.join(root, TEMPLATE), "utf8");
  const readme = await readFile(path.join(root, PLAN_DIR, "README.md"), "utf8");
  const php = phpCoverage(await readOptional(path.resolve(root, options.phpClover)));
  const js = jsCoverage(await readOptional(path.resolve(root, options.jsSummary)));
  const results = await prepareDirectory(
    path.resolve(root, options.results),
    path.resolve(root, options.prepared),
    phpTestTitles(phpTests),
  );
  const page = renderPage(template, {
    repo: repoUrl(options.repo),
    sha: options.sha,
    summary: summarizeRun(results),
    php,
    js,
    planIds,
    testFiles: testFilesById(tests),
    matrix: pathMatrix(readme, planIds),
    files: new Set(files),
  });

  return { results, page };
};

/**
 * Сборка или причина, по которой честного отчёта не собрать. Прочие ошибки (диск, права) не маскируются.
 *
 * @param {string} root
 * @param {ReportOptions} options
 * @returns {Promise<{ results: AllureResult[], page: string } | ReportError>}
 */
const buildOrReason = async (root, options) => {
  try {
    return await buildReport(root, options);
  } catch (error) {
    if (error instanceof ReportError) {
      return error;
    }
    throw error;
  }
};

/**
 * Готовит Allure-результаты и собирает главную страницу отчёта.
 *
 * @param {CliOptions} cli
 * @returns {Promise<number>} EXIT_OK, EXIT_BROKEN (из входных данных честного отчёта не собрать) или EXIT_USAGE
 */
export async function run({ root, argv, write }) {
  const options = parseOptions(argv);
  if (options === undefined) {
    write(USAGE);

    return EXIT_USAGE;
  }
  const built = await buildOrReason(root, options);
  if (built instanceof ReportError) {
    write(`tools/report: ${built.message}\n`);

    return EXIT_BROKEN;
  }
  const page = path.resolve(root, options.page);
  await writeFile(page, built.page);
  write(`tools/report: результатов ${built.results.length}, страница ${page}\n`);

  return EXIT_OK;
}
