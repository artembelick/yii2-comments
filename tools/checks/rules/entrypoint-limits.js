// @ts-check
import { findControlFlow } from "../control-flow.js";
import { maskSource } from "../source.js";

/** @typedef {import("../source.js").SourceFile} SourceFile */
/** @typedef {import("../source.js").Violation} Violation */

const MAX_LINES = 60;
const KEYWORDS = ["elseif", "if", "match", "switch", "foreach", "for", "while", "do"];

/**
 * Entrypoint'ы исключены из покрытия, поэтому логики в них быть не должно (ADR 0001):
 * без ветвлений и циклов, не длиннее 60 строк.
 */
export const entrypointLimits = {
  id: "entrypoint-limits",
  files: ["ws/runtime/**/*.php", "backend/web/js/main.js"],
  exclude: [],
  /**
   * @param {SourceFile} file
   * @returns {Violation[]}
   */
  check(file) {
    const code = maskSource(file, { comments: true, strings: true });
    const lines = file.text.replace(/\n$/u, "").split("\n").length;
    const length =
      lines > MAX_LINES ? [{ line: MAX_LINES + 1, message: `в entrypoint ${lines} строк, лимит ${MAX_LINES}` }] : [];

    return [
      ...findControlFlow(
        code,
        KEYWORDS,
        (construct) => `«${construct}» запрещён в entrypoint: логика уходит в классы и модули под unit-тестами`,
      ),
      ...length,
    ];
  },
};
