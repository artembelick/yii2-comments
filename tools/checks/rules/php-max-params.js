// @ts-check
import { lineAt, maskSource } from "../source.js";

/** @typedef {import("../source.js").SourceFile} SourceFile */
/** @typedef {import("../source.js").Violation} Violation */

const MAX_PARAMS = 5;
const DECLARATION = /\b(?:function|fn)\s*&?\s*\w*\s*\(/gu;
const OPENING = new Set(["(", "[", "{"]);
const CLOSING = new Set([")", "]", "}"]);

/**
 * Число параметров в списке, который начинается сразу после открывающей скобки.
 * Запятые внутри вложенных скобок (значения по умолчанию, атрибуты) не считаются.
 *
 * @param {string} code
 * @param {number} start индекс первого символа после `(`
 * @returns {number}
 */
const countParams = (code, start) => {
  let depth = 1;
  let commas = 0;
  let index = start;

  while (depth > 0) {
    const char = code.charAt(index);
    depth += Number(OPENING.has(char)) - Number(CLOSING.has(char));
    commas += Number(depth === 1 && char === ",");
    index += 1;
  }

  const list = code.slice(start, index - 1).trim();
  const trailingComma = Number(list.endsWith(","));

  return list === "" ? 0 : commas + 1 - trailingComma;
};

/** Лимит «до 5 параметров» для PHP (docs/test-plans/README.md). В phpcs и slevomat такого sniff нет. */
export const phpMaxParams = {
  id: "php-max-params",
  files: ["{common,frontend,backend,console,ws}/**/*.php"],
  exclude: [],
  /**
   * @param {SourceFile} file
   * @returns {Violation[]}
   */
  check(file) {
    const code = maskSource(file, { comments: true, strings: true });

    return [...code.matchAll(DECLARATION)].flatMap((match) => {
      const params = countParams(code, match.index + match[0].length);

      return params > MAX_PARAMS
        ? [{ line: lineAt(code, match.index), message: `${params} параметров, лимит ${MAX_PARAMS}` }]
        : [];
    });
  },
};
