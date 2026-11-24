// @ts-check

/** Сколько секунд строка нового комментария подсвечена. */
const HIGHLIGHT_MS = 3000;

/** @typedef {import("./api.js").SerializedComment} SerializedComment */
/** @typedef {import("./ws-client.js").CommentEvent} CommentEvent */

/**
 * @typedef {object} ListView Какую страницу показывает список.
 * @property {string} status вкладка: active или deleted
 * @property {number} page
 * @property {number} perPage
 */

/**
 * @typedef {object} CommentListDependencies
 * @property {HTMLTableElement} table
 * @property {HTMLTableSectionElement} tbody
 * @property {HTMLElement} emptyState текст пустой вкладки
 * @property {ListView} view
 * @property {(comment: SerializedComment) => HTMLTableRowElement} renderRow
 * @property {HTMLElement} banner баннер «N новых» со ссылкой на первую страницу «Активных»
 * @property {(n: number) => string} bannerText
 * @property {{ setTimeout: (handler: () => void, ms: number) => unknown }} timers
 */

/** Состояние списка на странице: показанные строки и новые комментарии, которых на ней не видно. */
class CommentList {
  /**
   * @readonly
   * @type {CommentListDependencies}
   */
  #deps;

  /**
   * Новые комментарии, которых не видно на этой странице: по id, чтобы повтор события не считался дважды.
   *
   * @readonly
   */
  #unseen = new Set();

  /** @param {CommentListDependencies} dependencies */
  constructor(dependencies) {
    this.#deps = dependencies;
  }

  /** @param {CommentEvent} event */
  apply(event) {
    if (event.type !== "comment.created" || this.#isShown(event.comment.id)) {
      return;
    }
    if (this.#showsNewest()) {
      this.#insertNewest(event.comment);
    } else {
      this.#countUnseen(event.comment);
    }
  }

  /** @param {SerializedComment[]} items */
  replaceAll(items) {
    this.#deps.tbody.replaceChildren(...items.map((item) => this.#deps.renderRow(item)));
    this.#unseen.clear();
    this.#deps.banner.hidden = true;
    this.#syncEmptyState();
  }

  /** @returns {boolean} новый комментарий встаёт на эту страницу первой строкой */
  #showsNewest() {
    return this.#deps.view.status === "active" && this.#deps.view.page === 1;
  }

  /**
   * @param {number} id
   * @returns {boolean}
   */
  #isShown(id) {
    return [...this.#deps.tbody.rows].some((row) => row.dataset["id"] === String(id));
  }

  #syncEmptyState() {
    this.#deps.table.hidden = this.#deps.tbody.rows.length === 0;
    this.#deps.emptyState.hidden = !this.#deps.table.hidden;
  }

  /** @param {SerializedComment} comment */
  #insertNewest(comment) {
    const row = this.#deps.renderRow(comment);
    row.dataset["highlighted"] = "true";
    this.#deps.tbody.prepend(row);
    for (const extra of [...this.#deps.tbody.rows].slice(this.#deps.view.perPage)) {
      extra.remove();
    }
    this.#syncEmptyState();
    this.#deps.timers.setTimeout(() => {
      delete row.dataset["highlighted"];
    }, HIGHLIGHT_MS);
  }

  /** @param {SerializedComment} comment */
  #countUnseen(comment) {
    this.#unseen.add(comment.id);
    this.#deps.banner.textContent = this.#deps.bannerText(this.#unseen.size);
    this.#deps.banner.hidden = false;
  }
}

/**
 * Список админки, который обновляется событиями WS. Новый комментарий встаёт первой строкой только на первой
 * странице «Активных»: на других видах строки не сдвигаются, а баннер считает новые комментарии.
 *
 * @param {CommentListDependencies} dependencies
 * @returns {{ apply(event: CommentEvent): void, replaceAll(items: SerializedComment[]): void }}
 */
export function createCommentList(dependencies) {
  const list = new CommentList(dependencies);

  return {
    apply(event) {
      list.apply(event);
    },
    replaceAll(items) {
      list.replaceAll(items);
    },
  };
}
