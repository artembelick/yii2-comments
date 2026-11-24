// @ts-check
import { UnauthorizedError } from "./unauthorized-error.js";

/** Клиент шлёт ping, чтобы прокси и сервер не закрыли тихое соединение (ADR 0002). */
const PING_INTERVAL_MS = 25_000;

/** Код закрытия «сессия завершена» (logout, блокировка, смена пароля): переподключаться бессмысленно (ADR 0002). */
const SESSION_ENDED = 4403;

/** События комментариев, которые рассылает WS-сервер (docs/test-plans/regression.md, «WS-контракты»). */
const COMMENT_EVENTS = new Set(["comment.created", "comment.updated", "comment.deleted", "comment.restored"]);

/** @typedef {import("./status-indicator.js").WsStatus} WsStatus */

/**
 * @typedef {object} CommentEvent
 * @property {"comment.created" | "comment.updated" | "comment.deleted" | "comment.restored"} type
 * @property {import("./api.js").SerializedComment} comment
 */
/** @typedef {import("./backoff.js").Backoff} Backoff */

/** @typedef {Pick<WebSocket, "send" | "close" | "addEventListener">} WsSocket То, чем клиент пользуется у WebSocket. */

/**
 * @typedef {object} Timers
 * @property {(handler: () => void, ms: number) => number} setTimeout
 * @property {(id: number | undefined) => void} clearTimeout
 * @property {(handler: () => void, ms: number) => number} setInterval
 * @property {(id: number | undefined) => void} clearInterval
 */

/**
 * @typedef {object} WsClientDependencies
 * @property {string} url
 * @property {{ fetchToken: () => Promise<string> }} api
 * @property {Backoff} backoff
 * @property {new (url: string) => WsSocket} WebSocketImpl
 * @property {Timers} timers
 * @property {(status: WsStatus) => void} onStatus
 * @property {() => void} navigateToLogin
 * @property {(event: CommentEvent) => void} onEvent
 * @property {() => void} onReconnected Соединение восстановлено после разрыва: пропущенные события не хранятся,
 *   страницу нужно перечитать (ADR 0002).
 */

/**
 * Тип сообщения сервера. Кадр не JSON или без строкового type — пустой тип, а не ошибка:
 * исключение в обработчике message клиенту ничего бы не дало.
 *
 * @param {MessageEvent} event
 * @returns {{ type: string, message: unknown }}
 */
const parse = (event) => {
  try {
    /** @type {unknown} */
    const message = JSON.parse(String(event.data));
    const type =
      typeof message === "object" && message !== null && "type" in message && typeof message.type === "string"
        ? message.type
        : "";

    return { type, message };
  } catch {
    return { type: "", message: undefined };
  }
};

/**
 * Событие комментария: известный type и объект comment. Поля комментария собирает сам сервер
 * (CommentSerializer), их контракт держат REG-WS-07 и UNIT-SER-01.
 *
 * @param {unknown} message
 * @returns {message is CommentEvent}
 */
const isCommentEvent = (message) =>
  typeof message === "object" &&
  message !== null &&
  "type" in message &&
  typeof message.type === "string" &&
  COMMENT_EVENTS.has(message.type) &&
  "comment" in message &&
  typeof message.comment === "object" &&
  message.comment !== null;

/** Состояние одного клиента: текущее соединение, таймеры ping и повтора. */
class WsClient {
  /**
   * @readonly
   * @type {WsClientDependencies}
   */
  #deps;

  #stopped = false;

  /** Хотя бы одно соединение уже было авторизовано: следующее auth.ok — это переподключение. */
  #wasOnline = false;

  /** @type {WsSocket | undefined} */
  #socket;

  /** @type {number | undefined} */
  #pingTimer;

  /** @type {number | undefined} */
  #retryTimer;

  /** @param {WsClientDependencies} dependencies */
  constructor(dependencies) {
    this.#deps = dependencies;
  }

  start() {
    this.#stopped = false;
    this.#deps.onStatus("reconnecting");
    // Попытку не ждут: #connect не падает, сбой уходит в backoff или на логин.
    void this.#connect();
  }

  stop() {
    this.#stopped = true;
    this.#deps.timers.clearTimeout(this.#retryTimer);
    this.#deps.timers.clearInterval(this.#pingTimer);
    this.#socket?.close();
  }

  async #connect() {
    try {
      this.#open(await this.#deps.api.fetchToken());
    } catch (error) {
      if (error instanceof UnauthorizedError) {
        this.#deps.navigateToLogin();
      } else {
        this.#scheduleReconnect();
      }
    }
  }

  /** @param {string} token */
  #open(token) {
    // Пока ждали токен, мог прийти stop(): тогда соединение уже не нужно.
    if (this.#stopped) {
      return;
    }
    const socket = new this.#deps.WebSocketImpl(this.#deps.url);
    this.#socket = socket;
    socket.addEventListener("open", () => {
      socket.send(JSON.stringify({ type: "auth", token }));
    });
    socket.addEventListener("message", (event) => {
      const { type, message } = parse(event);
      if (type === "auth.ok") {
        this.#online(socket);
      } else if (isCommentEvent(message)) {
        this.#deps.onEvent(message);
      }
    });
    socket.addEventListener("close", (event) => {
      this.#deps.timers.clearInterval(this.#pingTimer);
      if (event.code === SESSION_ENDED) {
        this.#deps.onStatus("offline");
        this.#deps.navigateToLogin();
      } else {
        this.#scheduleReconnect();
      }
    });
  }

  /** @param {WsSocket} socket */
  #online(socket) {
    this.#deps.onStatus("online");
    this.#deps.backoff.reset();
    if (this.#wasOnline) {
      this.#deps.onReconnected();
    }
    this.#wasOnline = true;
    this.#pingTimer = this.#deps.timers.setInterval(() => {
      socket.send(JSON.stringify({ type: "ping" }));
    }, PING_INTERVAL_MS);
  }

  #scheduleReconnect() {
    // После stop() новых попыток нет, даже если разрыв или отказ в токене пришли позже.
    if (this.#stopped) {
      return;
    }
    this.#deps.onStatus("reconnecting");
    this.#retryTimer = this.#deps.timers.setTimeout(() => {
      void this.#connect();
    }, this.#deps.backoff.next());
  }
}

/**
 * Соединение админки с WS-сервером (ADR 0002): одноразовый токен, auth первым сообщением, ping каждые 25 секунд.
 * После разрыва — пауза backoff, новый токен и новое соединение. Токен не выдали (401) или сервер закрыл
 * соединение с 4403 (сессия завершена) — на логин без переподключения.
 *
 * @param {WsClientDependencies} dependencies
 * @returns {{ start(): void, stop(): void }}
 */
export function createWsClient(dependencies) {
  const client = new WsClient(dependencies);

  return {
    start() {
      client.start();
    },
    stop() {
      client.stop();
    },
  };
}
