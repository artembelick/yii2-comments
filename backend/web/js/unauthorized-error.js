// @ts-check

/** Сессия админа закончилась (ответ 401): клиент уводит на страницу логина. */
export class UnauthorizedError extends Error {
  /**
   * @param {string} message
   * @param {ErrorOptions} [options]
   */
  constructor(message, options) {
    super(message, options);
    this.name = "UnauthorizedError";
  }
}
