// @ts-check
import { readdir, readFile } from "node:fs/promises";
import path from "node:path";

import { checkClover } from "./coverage.js";
import { globToRegExp } from "./glob.js";
import { checkJunit } from "./junit.js";
import { RULES } from "./rules/index.js";
import { extractPlanIds } from "./rules/test-ids.js";

/** @typedef {import("./rules/index.js").Rule} Rule */
/** @typedef {{ root: string, argv: readonly string[], write: (chunk: string) => void }} CliOptions */

/** Сгенерированное, зависимости и отчёты не проверяются. ws/runtime — код, а не runtime Yii, поэтому он проверяется. */
const SKIPPED = [
  ".git/**",
  "vendor/**",
  "**/node_modules/**",
  "{frontend,backend,console}/runtime/**",
  "**/_output/**",
  "**/_generated/**",
  "**/web/assets/**",
  "e2e/{test-results,playwright-report}/**",
].map((glob) => globToRegExp(glob));
const PLAN_DIR = "docs/test-plans";
/** Коды выхода. */
export const EXIT_OK = 0;
export const EXIT_VIOLATIONS = 1;
export const EXIT_USAGE = 2;
const USAGE =
  "использование: node tools/checks/bin.js [--allow-empty] | coverage <clover.xml> --strip=<префикс пути в отчёте> | junit <junit.xml>\n";

/**
 * Все файлы дерева относительными путями через `/`, по алфавиту.
 *
 * @param {string} root
 * @returns {Promise<string[]>}
 */
const listFiles = async (root) => {
  const entries = await readdir(root, { recursive: true, withFileTypes: true });

  return entries
    .filter((entry) => entry.isFile())
    .map((entry) => path.relative(root, path.join(entry.parentPath, entry.name)).split(path.sep).join("/"))
    .filter((file) => !SKIPPED.some((glob) => glob.test(file)))
    .toSorted();
};

/**
 * @param {Rule} rule
 * @param {readonly string[]} files
 * @returns {string[]}
 */
const filesOf = (rule, files) => {
  const included = rule.files.map((glob) => globToRegExp(glob));
  const excluded = rule.exclude.map((glob) => globToRegExp(glob));

  return files.filter((file) => included.some((glob) => glob.test(file)) && !excluded.some((glob) => glob.test(file)));
};

/**
 * @param {CliOptions} options
 * @param {boolean} allowEmpty
 * @returns {Promise<number>}
 */
const lint = async ({ root, write }, allowEmpty) => {
  const files = await listFiles(root);
  /**
   * @param {string} file
   * @returns {Promise<import("./source.js").SourceFile>}
   */
  const read = async (file) => ({ path: file, text: await readFile(path.join(root, file), "utf8") });
  const plans = await Promise.all(
    files.filter((file) => file.startsWith(`${PLAN_DIR}/`)).map(async (file) => read(file)),
  );
  const context = { planIds: extractPlanIds(plans) };

  const problems = await Promise.all(
    RULES.map(async (rule) => {
      const matched = filesOf(rule, files);
      const empty =
        matched.length === 0 && !allowEmpty
          ? [`${rule.id}: ни одного файла по ${rule.files.join(", ")} — правило ничего не проверяет`]
          : [];
      const sources = await Promise.all(matched.map(async (file) => read(file)));
      const violations = sources.flatMap((source) =>
        rule
          .check(source, context)
          .map((violation) => `${rule.id}: ${source.path}:${violation.line} ${violation.message}`),
      );

      return [...empty, ...violations];
    }),
  );
  const lines = problems.flat();

  write(lines.map((line) => `${line}\n`).join(""));
  write(lines.length === 0 ? "tools/checks: нарушений нет\n" : `tools/checks: нарушений: ${lines.length}\n`);

  return lines.length === 0 ? EXIT_OK : EXIT_VIOLATIONS;
};

/**
 * Проверка отчёта тестов: читает файл и печатает найденные проблемы или итог.
 *
 * @param {CliOptions} options
 * @param {string} report путь к отчёту от корня
 * @param {(xml: string) => string[]} check
 * @param {string} success итог, когда проблем нет
 * @returns {Promise<number>}
 */
const checkReport = async ({ root, write }, report, check, success) => {
  const xml = await readFile(path.join(root, report), "utf8").catch(() => false);
  const problems = typeof xml === "string" ? check(xml) : [`нет отчёта ${report}: unit-тесты PHP не запускались?`];

  write(problems.length === 0 ? `${success}\n` : problems.map((line) => `${line}\n`).join(""));

  return problems.length === 0 ? EXIT_OK : EXIT_VIOLATIONS;
};

/**
 * Свои проверки проекта. По умолчанию — правила из rules/; `coverage` — порог покрытия PHP;
 * `junit` — тесты без проверок, пропуски и предупреждения в отчёте Codeception.
 *
 * @param {CliOptions} options
 * @returns {Promise<number>} код выхода: EXIT_OK, EXIT_VIOLATIONS или EXIT_USAGE
 */
export async function run(options) {
  const [command, report, strip] = options.argv;

  if (command === undefined || command === "--allow-empty") {
    return lint(options, command === "--allow-empty");
  }
  if (command === "coverage" && report !== undefined && strip?.startsWith("--strip=") === true) {
    const prefix = strip.slice("--strip=".length);

    return checkReport(options, report, (xml) => checkClover(xml, prefix), "tools/checks coverage: покрытие PHP 100%");
  }
  if (command === "junit" && report !== undefined) {
    return checkReport(
      options,
      report,
      (xml) => checkJunit(xml),
      "tools/checks junit: тестов без проверок, пропусков и предупреждений нет",
    );
  }
  options.write(USAGE);

  return EXIT_USAGE;
}
