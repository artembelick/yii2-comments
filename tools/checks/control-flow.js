// @ts-check
import { findAll } from "./source.js";

/** @typedef {import("./source.js").Violation} Violation */

/**
 * Шаблон ветвлений, циклов и тернарных операторов. Ищется в тексте без строк и комментариев.
 * Вызов метода с тем же именем (`str.match(`, `$a->match(`) ветвлением не считается.
 *
 * @param {readonly string[]} keywords
 * @returns {RegExp}
 */
const controlFlow = (keywords) =>
  new RegExp(
    String.raw`(?<![.$>\w])(?:${keywords.join("|")})\b(?=\s*[({]|\s+if\b)|(?<![.$>\w])else\b|(?<=\s)\?:?(?=\s)`,
    "gu",
  );

/**
 * @param {string} code текст без строк и комментариев
 * @param {readonly string[]} keywords
 * @param {(construct: string) => string} message
 * @returns {Violation[]}
 */
export function findControlFlow(code, keywords, message) {
  return findAll(code, controlFlow(keywords), ([construct]) => message(construct));
}
