// @ts-check

/** На странице списка нет разметки, без которой клиент не настроить. Ошибка сразу, а не клиент без настроек. */
export class PageMarkupError extends Error {
  /** @param {string} message */
  constructor(message) {
    super(message);
    this.name = "PageMarkupError";
  }
}
