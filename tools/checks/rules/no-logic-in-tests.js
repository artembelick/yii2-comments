// @ts-check
import { findControlFlow } from "../control-flow.js";
import { maskSource } from "../source.js";
import { TEST_FILES } from "../test-locations.js";

/** @typedef {import("../source.js").SourceFile} SourceFile */
/** @typedef {import("../source.js").Violation} Violation */

const KEYWORDS = ["elseif", "if", "match", "switch", "foreach", "for", "while", "do", "try", "catch"];

/**
 * ЧТ-4: в тестовом файле нет логики. Проверяется весь файл, а не только тела тестов:
 * хелпер с ветвлением живёт в _support или e2e/support, где его видно и можно покрыть.
 */
export const noLogicInTests = {
  id: "no-logic-in-tests",
  files: TEST_FILES,
  exclude: [],
  /**
   * @param {SourceFile} file
   * @returns {Violation[]}
   */
  check(file) {
    return findControlFlow(
      maskSource(file, { comments: true, strings: true }),
      KEYWORDS,
      (construct) =>
        `«${construct}» в тесте: варианты — через провайдер, исключения — через expectException/toThrow, хелперы — в support`,
    );
  },
};
