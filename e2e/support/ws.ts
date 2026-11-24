import { on, once } from "node:events";
import { IncomingMessage, request } from "node:http";

import { WebSocket } from "ws";

import { requireEnv } from "./env.ts";

/** Адрес WS-сервера, как его видит браузер админки: nginx проксирует /ws на хосте админки. */
const wsUrl = (): string => `${requireEnv("ADMIN_URL").replace(/^http/u, "ws")}/ws`;

const closeCodeOf = async (socket: WebSocket): Promise<number> => {
  const args: unknown[] = await once(socket, "close");

  return Number(args[0]);
};

/**
 * Клиент REG-WS: Node-клиент `ws` без браузера. Сообщения сервера буферизуются с момента открытия,
 * тест ждёт следующее сообщение или код закрытия — условие, а не время (ДТ-3).
 */
export class WsProbe {
  readonly #socket: WebSocket;
  readonly #messages: AsyncIterator<unknown[]>;
  readonly #closed: Promise<number>;
  readonly #errors: Error[] = [];

  public constructor(socket: WebSocket) {
    this.#socket = socket;
    this.#messages = on(socket, "message", { close: ["close"] });
    this.#closed = closeCodeOf(socket);
    // Ошибку сокета ws сопровождает close с кодом 1006: тест видит её через closeCode(), а здесь она для отладки.
    socket.on("error", (error) => {
      this.#errors.push(error);
    });
  }

  public send(message: Record<string, unknown>): void {
    this.#socket.send(JSON.stringify(message));
  }

  public sendRaw(text: string): void {
    this.#socket.send(text);
  }

  /** Следующее сообщение сервера в порядке прихода. */
  public async nextMessage(): Promise<unknown> {
    const next = await this.#messages.next();
    const args: unknown[] = next.done === true ? [] : next.value;
    const [data] = args;
    if (!(data instanceof Buffer)) {
      throw new TypeError(`Сервер закрыл соединение, не прислав сообщения (ошибки: ${this.#errors.join("; ")})`);
    }

    return JSON.parse(data.toString("utf8"));
  }

  /** Код, с которым сервер закрыл соединение. */
  public async closeCode(): Promise<number> {
    return this.#closed;
  }

  public close(): void {
    this.#socket.close();
  }
}

/** Открывает WS с Origin админки и ждёт завершения handshake. */
export async function openWs(): Promise<WsProbe> {
  const socket = new WebSocket(wsUrl(), { origin: requireEnv("ADMIN_URL") });
  const probe = new WsProbe(socket);
  await once(socket, "open");

  return probe;
}

/** Открывает WS и проходит авторизацию токеном: первым сообщением auth, ответ auth.ok. */
export async function openAuthenticatedWs(token: string): Promise<WsProbe> {
  const probe = await openWs();
  probe.send({ type: "auth", token });
  const reply = await probe.nextMessage();
  if (JSON.stringify(reply) !== JSON.stringify({ type: "auth.ok" })) {
    throw new Error(`Авторизация по WS не прошла: ${JSON.stringify(reply)}`);
  }

  return probe;
}

/**
 * Статус ответа на запрос handshake WebSocket с заданным Origin. Запрос собран вручную через node:http:
 * отказ сервера — обычный HTTP-ответ, клиенту ws его не нужно разбирать.
 */
export async function handshakeStatus(origin: string): Promise<number> {
  const handshake = request(`${requireEnv("ADMIN_URL")}/ws`, {
    headers: {
      Connection: "Upgrade",
      Upgrade: "websocket",
      "Sec-WebSocket-Version": "13",
      "Sec-WebSocket-Key": "dGhlIHNhbXBsZSBub25jZQ==",
      Origin: origin,
    },
  });
  handshake.end();
  const args: unknown[] = await once(handshake, "response");
  const [response] = args;
  if (!(response instanceof IncomingMessage)) {
    throw new TypeError("Ответа на handshake нет");
  }
  response.resume();

  return Number(response.statusCode);
}
