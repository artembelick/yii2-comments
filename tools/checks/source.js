// @ts-check
/**
 * Маскирование исходников для проверок по регулярным выражениям.
 * Замаскированные символы заменяются пробелами, переводы строк сохраняются,
 * поэтому номер строки в замаскированном тексте совпадает с исходным.
 */

/** @typedef {{ line: number, message: string }} Violation */
/** @typedef {{ path: string, text: string }} SourceFile */
/** @typedef {{ comments: boolean, strings: boolean }} MaskOptions */

/**
 * @param {string} path
 * @returns {boolean}
 */
const isPhp = (path) => path.endsWith(".php");

/**
 * @param {string} chunk
 * @returns {string}
 */
const blank = (chunk) => chunk.replaceAll(/[^\n]/gu, " ");

const PHP_TOKENS =
  /\/\*[\s\S]*?(?:\*\/|$)|\/\/[^\n]*|#(?!\[)[^\n]*|'(?:\\[\s\S]|[^'\\])*'|"(?:\\[\s\S]|[^"\\])*"|<<<[ \t]*(['"]?)(\w+)\1\n[\s\S]*?\n[ \t]*\2\b/gu;
const JS_TOKENS =
  /\/\*[\s\S]*?(?:\*\/|$)|\/\/[^\n]*|'(?:\\[\s\S]|[^'\\])*'|"(?:\\[\s\S]|[^"\\])*"|`(?:\\[\s\S]|[^`\\])*`/gu;

/**
 * @param {string} token
 * @returns {boolean}
 */
const isComment = (token) => token.startsWith("/") || token.startsWith("#");

/**
 * Скрывает комментарии и/или строковые литералы PHP или JS/TS.
 *
 * @param {SourceFile} file
 * @param {MaskOptions} mask
 * @returns {string}
 */
export function maskSource({ path, text }, mask) {
  const tokens = isPhp(path) ? PHP_TOKENS : JS_TOKENS;

  return text.replaceAll(tokens, (token) => {
    const hide = isComment(token) ? mask.comments : mask.strings;

    return hide ? blank(token) : token;
  });
}

/**
 * Номер строки (с 1) для смещения в тексте.
 *
 * @param {string} text
 * @param {number} offset
 * @returns {number}
 */
export function lineAt(text, offset) {
  return text.slice(0, offset).split("\n").length;
}

/**
 * Все совпадения шаблона в тексте как нарушения с номером строки.
 *
 * @param {string} text
 * @param {RegExp} pattern глобальный шаблон
 * @param {(match: RegExpExecArray) => string} message
 * @returns {Violation[]}
 */
export function findAll(text, pattern, message) {
  return [...text.matchAll(pattern)].map((match) => ({
    line: lineAt(text, match.index),
    message: message(match),
  }));
}

/**
 * Пара ключ — значение для `new Map(…)`: литерал массива TypeScript вывел бы как `string[]`, а не как пару.
 *
 * @param {string} key
 * @param {string} value
 * @returns {[string, string]}
 */
export const pair = (key, value) => [key, value];
