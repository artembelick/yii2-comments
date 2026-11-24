// @ts-check
import { TEST_FILES } from "../globs.js";
import { findAll, maskSource } from "../source.js";

/** @typedef {import("../source.js").SourceFile} SourceFile */
/** @typedef {import("../source.js").Violation} Violation */

/** Вызовы, которые всегда читают реальное время. Ищутся в коде без строк и комментариев. */
const CALLS = /(?<![.$>:\w])\\?((?:time|date|microtime|hrtime|strtotime)\s*\(|(?:Date|performance)\.now\()/gu;
/** Конструкторы дат без аргумента или с 'now'. Ищутся в коде без комментариев: аргумент-строка важен. */
const CONSTRUCTORS = /new\s+\\?(?:DateTime(?:Immutable)?|Date)\(\s*(?:'now'|"now")?\s*\)/gu;

/**
 * @param {RegExpExecArray} match
 * @returns {string}
 */
const message = ([found, call]) =>
  `«${(call ?? found).replaceAll(/\s+\(/gu, "(")}» — реальное время в тесте: используйте ClockInterface/MockClock или vi.useFakeTimers()`;

/** ДТ-1: реального времени в тестах нет. */
export const noRealTime = {
  id: "no-real-time",
  files: TEST_FILES,
  exclude: [],
  /**
   * @param {SourceFile} file
   * @returns {Violation[]}
   */
  check(file) {
    const code = maskSource(file, { comments: true, strings: true });
    const withStrings = maskSource(file, { comments: true, strings: false });

    return [...findAll(code, CALLS, message), ...findAll(withStrings, CONSTRUCTORS, message)].toSorted(
      (left, right) => left.line - right.line,
    );
  },
};
