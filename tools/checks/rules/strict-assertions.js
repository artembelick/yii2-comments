// @ts-check
import { TEST_FILES } from "../globs.js";
import { findAll, maskSource } from "../source.js";

/** @typedef {import("../source.js").SourceFile} SourceFile */
/** @typedef {import("../source.js").Violation} Violation */

const LOOSE = /\b(assert(?:Not)?Equals\w*|toEqual|toBeTruthy|toBeFalsy)(?=\s*\()/gu;

/** @type {Record<string, string>} */
const INSTEAD = {
  toEqual: "toStrictEqual",
  toBeTruthy: "toBe(true)",
  toBeFalsy: "toBe(false)",
};

/**
 * @param {string} found
 * @returns {string}
 */
const strictFor = (found) => INSTEAD[found] ?? (found.startsWith("assertNot") ? "assertNotSame" : "assertSame");

/** ПР-4: проверки точные — assertSame, toStrictEqual, toBe. */
export const strictAssertions = {
  id: "strict-assertions",
  files: TEST_FILES,
  exclude: [],
  /**
   * @param {SourceFile} file
   * @returns {Violation[]}
   */
  check(file) {
    return findAll(
      maskSource(file, { comments: true, strings: true }),
      LOOSE,
      ([found]) => `«${found}» — нестрогая проверка: используйте ${strictFor(found)}`,
    );
  },
};
