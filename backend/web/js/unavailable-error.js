// @ts-check

/** Бэкенд или сеть не ответили как надо: клиент попробует снова через backoff. */
export class UnavailableError extends Error {
  /**
   * @param {string} message
   * @param {ErrorOptions} [options]
   */
  constructor(message, options) {
    super(message, options);
    this.name = "UnavailableError";
  }
}
