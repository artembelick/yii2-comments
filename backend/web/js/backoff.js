// @ts-check

/** Во сколько раз растёт пауза после каждой неудачной попытки. */
const GROWTH = 2;

/**
 * @typedef {object} Backoff
 * @property {() => number} next Пауза перед следующей попыткой, мс.
 * @property {() => void} reset Начать сначала: соединение снова удалось.
 */

/**
 * Паузы между переподключениями к WS (ADR 0002): 1, 2, 4… секунды, но не больше 30.
 *
 * @param {{ baseMs?: number, maxMs?: number }} [options]
 * @returns {Backoff}
 */
export function createBackoff({ baseMs = 1000, maxMs = 30_000 } = {}) {
  let attempt = 0;

  return {
    next() {
      const delay = Math.min(baseMs * GROWTH ** attempt, maxMs);
      attempt += 1;

      return delay;
    },
    reset() {
      attempt = 0;
    },
  };
}
