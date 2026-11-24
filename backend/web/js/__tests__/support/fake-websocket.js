// @ts-check

/**
 * @typedef {object} FakeSocket WebSocket для тестов клиента: запоминает отправленное, события тест вызывает сам.
 * @property {string} url
 * @property {unknown[]} sent
 * @property {boolean} closed
 * @property {(data: string) => void} send
 * @property {() => void} close
 * @property {EventTarget["addEventListener"]} addEventListener
 * @property {() => void} open
 * @property {(message: unknown) => void} receive
 * @property {(text: string) => void} receiveRaw
 * @property {(code: number) => void} drop
 */

/**
 * Конструктор WebSocket для клиента и список сокетов, которые клиент открыл. Свой на каждый тест (ИЗ-6).
 *
 * @returns {{ WebSocketImpl: new (url: string) => FakeSocket, sockets: FakeSocket[] }}
 */
export function fakeWebSocket() {
  /** @type {FakeSocket[]} */
  const sockets = [];

  class Socket extends EventTarget {
    /** @type {unknown[]} */
    sent = [];

    closed = false;

    /** @param {string} url */
    constructor(url) {
      super();
      this.url = url;
      sockets.push(this);
    }

    /** @param {string} data */
    send(data) {
      this.sent.push(JSON.parse(data));
    }

    close() {
      this.closed = true;
    }

    open() {
      this.dispatchEvent(new Event("open"));
    }

    /** @param {unknown} message */
    receive(message) {
      this.receiveRaw(JSON.stringify(message));
    }

    /** @param {string} text */
    receiveRaw(text) {
      this.dispatchEvent(new MessageEvent("message", { data: text }));
    }

    /** @param {number} code */
    drop(code) {
      this.dispatchEvent(new CloseEvent("close", { code }));
    }
  }

  return { WebSocketImpl: Socket, sockets };
}
