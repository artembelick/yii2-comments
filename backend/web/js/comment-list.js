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

/**
 * Комментарий стоит выше строки: порядок списка — новые сверху, при равной дате больший id выше.
 * Даты в ISO 8601 UTC одного формата, поэтому сравниваются как строки.
 *
 * @param {SerializedComment} comment
 * @param {HTMLTableRowElement} row
 * @returns {boolean}
 */
const isNewer = (comment, row) => {
  const rowCreatedAt = String(row.dataset["createdAt"]);
  if (comment.createdAt !== rowCreatedAt) {
    return comment.createdAt > rowCreatedAt;
  }

  return comment.id > Number(row.dataset["id"]);
};

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
    if (event.type === "comment.created") {
      this.#applyCreated(event.comment);
    } else if (event.comment.status !== this.#deps.view.status) {
      /*
       * Комментарию не место на этой вкладке. Для updated это снимок, опубликованный после удаления
       * или восстановления: перерисовать строку по нему значило бы показать кнопки чужого статуса.
       */
      this.#rowOf(event.comment.id)?.remove();
      this.#syncEmptyState();
    } else if (event.type === "comment.updated") {
      this.#rowOf(event.comment.id)?.replaceWith(this.#deps.renderRow(event.comment));
    } else {
      this.#place(event.comment);
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

  /** @param {SerializedComment} comment */
  #applyCreated(comment) {
    if (this.#rowOf(comment.id) !== undefined) {
      return;
    }
    if (this.#showsNewest()) {
      this.#insertNewest(comment);
    } else {
      this.#countUnseen(comment);
    }
  }

  /**
   * @param {number} id
   * @returns {HTMLTableRowElement | undefined}
   */
  #rowOf(id) {
    return [...this.#deps.tbody.rows].find((row) => row.dataset["id"] === String(id));
  }

  /**
   * Удалённый или восстановленный комментарий встаёт на своё место по дате создания, если оно на этой странице:
   * новее первой строки — место на предыдущей странице (кроме первой), старше последней на полной — на следующей.
   *
   * @param {SerializedComment} comment
   */
  #place(comment) {
    if (this.#rowOf(comment.id) !== undefined) {
      return;
    }
    const rows = [...this.#deps.tbody.rows];
    const next = rows.find((row) => isNewer(comment, row));
    const onEarlierPage = next === rows[0] && this.#deps.view.page > 1;
    const onLaterPage = next === undefined && rows.length >= this.#deps.view.perPage;
    if (onEarlierPage || onLaterPage) {
      return;
    }
    const row = this.#deps.renderRow(comment);
    if (next === undefined) {
      this.#deps.tbody.append(row);
    } else {
      next.before(row);
    }
    this.#trimToPage();
    this.#syncEmptyState();
  }

  /** На полной странице лишние строки снизу уходят на следующую. */
  #trimToPage() {
    for (const extra of [...this.#deps.tbody.rows].slice(this.#deps.view.perPage)) {
      extra.remove();
    }
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
    this.#trimToPage();
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
 * странице «Активных»: на других видах строки не сдвигаются, а баннер считает новые комментарии. Правка
 * перерисовывает строку на месте. Удалённый уходит с «Активных» и встаёт на своё место на «Удалённых»,
 * восстановленный — наоборот; освободившееся место страница не дозаполняет.
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
