import { findAll, maskSource } from "../source.js";
// @ts-check
import { TEST_FILES } from "../test-locations.js";

/** @typedef {import("../source.js").SourceFile} SourceFile */
/** @typedef {import("../source.js").Violation} Violation */

const WAITS = /(?<![.$>:\w])\\?(?:u?sleep|time_nanosleep)(?=\s*\()|\bwaitForTimeout(?=\s*\()/gu;
/** В Playwright реальный setTimeout — это sleep. В Vitest он под vi.useFakeTimers() и бывает частью проверяемого кода. */
const E2E_WAITS = /(?<![.$>:\w])setTimeout(?=\s*\()/gu;

/**
 * @param {RegExpExecArray} match
 * @returns {string}
 */
const message = ([found]) =>
  `«${found.replace(/^\\/u, "")}(» — фиксированное ожидание: ждите условие (автоожидание, expect.poll, toPass)`;

/** ДТ-3: никаких sleep, usleep и waitForTimeout. */
export const noFixedWaits = {
  id: "no-fixed-waits",
  files: TEST_FILES,
  exclude: [],
  /**
   * @param {SourceFile} file
   * @returns {Violation[]}
   */
  check(file) {
    const code = maskSource(file, { comments: true, strings: true });
    const e2e = file.path.startsWith("e2e/") ? findAll(code, E2E_WAITS, message) : [];

    return [...findAll(code, WAITS, message), ...e2e];
  },
};
