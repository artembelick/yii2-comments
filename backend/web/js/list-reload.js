// @ts-check
import { UnauthorizedError } from "./unauthorized-error.js";

/** @typedef {import("./ws-client.js").CommentEvent} CommentEvent */
/** @typedef {import("./api.js").CommentPage} CommentPage */

/**
 * @typedef {object} ListReloadDependencies
 * @property {{ apply(event: CommentEvent): void, replaceAll(items: import("./api.js").SerializedComment[]): void }} list
 * @property {() => Promise<CommentPage>} fetchPage
 * @property {import("./backoff.js").Backoff} backoff
 * @property {{ setTimeout: (handler: () => void, ms: number) => number, clearTimeout: (id: number | undefined) => void }} timers
 * @property {() => void} navigateToLogin
 */

/** Список и перечитывание его страницы: события, пришедшие во время перечитывания, не теряются. */
class ListReload {
  /**
   * @readonly
   * @type {ListReloadDependencies}
   */
  #deps;

  /** Ответ перечитывания в пути: события копятся в #pending, а не уходят в список. */
  #reloading = false;

  /**
   * События, пришедшие, пока ответ в пути.
   *
   * @readonly
   * @type {CommentEvent[]}
   */
  #pending = [];

  /** Номер последнего перечитывания: ответ более раннего устарел и отбрасывается. */
  #latest = 0;

  /** @type {number | undefined} */
  #retryTimer;

  /** @param {ListReloadDependencies} dependencies */
  constructor(dependencies) {
    this.#deps = dependencies;
  }

  /** @param {CommentEvent} event */
  apply(event) {
    if (this.#reloading) {
      this.#pending.push(event);
    } else {
      this.#deps.list.apply(event);
    }
  }

  async reload() {
    this.#deps.timers.clearTimeout(this.#retryTimer);
    this.#latest += 1;
    const request = this.#latest;
    this.#reloading = true;
    try {
      const { items } = await this.#deps.fetchPage();
      if (request === this.#latest) {
        this.#deps.backoff.reset();
        this.#deps.list.replaceAll(items);
        this.#flush();
      }
    } catch (error) {
      if (error instanceof UnauthorizedError) {
        this.#deps.navigateToLogin();
      } else if (request === this.#latest) {
        this.#flush();
        this.#retryTimer = this.#deps.timers.setTimeout(() => {
          void this.reload();
        }, this.#deps.backoff.next());
      }
    }
  }

  #flush() {
    this.#reloading = false;
    for (const event of this.#pending.splice(0)) {
      this.#deps.list.apply(event);
    }
  }
}

/**
 * Перечитывание страницы списка после переподключения (ADR 0002). Пока ответ в пути, события WS копятся и
 * применяются поверх снимка: повтор уже показанного список отсекает сам.
 *
 * @param {ListReloadDependencies} dependencies
 * @returns {{ apply(event: CommentEvent): void, reload(): void }}
 */
export function createListReload(dependencies) {
  const reloading = new ListReload(dependencies);

  return {
    apply(event) {
      reloading.apply(event);
    },
    reload() {
      void reloading.reload();
    },
  };
}
