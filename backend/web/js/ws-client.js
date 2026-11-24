// @ts-check
import { UnauthorizedError } from "./unauthorized-error.js";

/** Клиент шлёт ping, чтобы прокси и сервер не закрыли тихое соединение (ADR 0002). */
const PING_INTERVAL_MS = 25_000;

/** @typedef {import("./status-indicator.js").WsStatus} WsStatus */
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
 */

/**
 * Кадр не JSON — не auth.ok, а не ошибка: исключение в обработчике message клиенту ничего бы не дало.
 *
 * @param {MessageEvent} event
 * @returns {boolean} сервер подтвердил авторизацию
 */
const isAuthOk = (event) => {
  try {
    /** @type {unknown} */
    const message = JSON.parse(String(event.data));

    return typeof message === "object" && message !== null && "type" in message && message.type === "auth.ok";
  } catch {
    return false;
  }
};

/** Состояние одного клиента: текущее соединение, таймеры ping и повтора. */
class WsClient {
  /**
   * @readonly
   * @type {WsClientDependencies}
   */
  #deps;

  #stopped = false;

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
      if (isAuthOk(event)) {
        this.#startPing(socket);
      }
    });
    socket.addEventListener("close", () => {
      this.#deps.timers.clearInterval(this.#pingTimer);
      this.#scheduleReconnect();
    });
  }

  /** @param {WsSocket} socket */
  #startPing(socket) {
    this.#deps.onStatus("online");
    this.#deps.backoff.reset();
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
 * После любого разрыва — пауза backoff, новый токен и новое соединение. Токен не выдали (401) — на логин.
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
