// @ts-check
/** Входные данные не дают собрать честный отчёт: битая ссылка, ID матрицы вне плана, тест продукта без ID. */
export class ReportError extends Error {
  /** @param {string} message */
  constructor(message) {
    super(message);
    this.name = "ReportError";
  }
}
