// @ts-check
import { describe, expect, it, onTestFinished, vi } from "vitest";

import { createBackoff } from "../backoff.js";
import { UnauthorizedError } from "../unauthorized-error.js";
import { UnavailableError } from "../unavailable-error.js";
import { createWsClient } from "../ws-client.js";
import { fakeWebSocket } from "./support/fake-websocket.js";

const URL = "ws://admin.comments.localhost/ws";

/**
 * Клиент с фейковым WebSocket, настоящим backoff и фейковыми таймерами. Таймеры возвращаются после теста (ИЗ-4).
 *
 * @param {() => Promise<string>} fetchToken очередь ответов api: vi.fn().mockResolvedValueOnce(…).mockRejectedValueOnce(…)
 * @returns {{
 *   wsClient: { start(): void, stop(): void },
 *   sockets: import("./support/fake-websocket.js").FakeSocket[],
 *   backoff: import("../backoff.js").Backoff,
 *   statuses: string[],
 *   logins: string[],
 *   events: unknown[],
 *   reconnects: string[],
 * }}
 */
const client = (fetchToken) => {
  vi.useFakeTimers();
  onTestFinished(() => {
    vi.useRealTimers();
  });
  const { WebSocketImpl, sockets } = fakeWebSocket();
  const backoff = createBackoff();
  /** @type {string[]} */
  const statuses = [];
  /** @type {string[]} */
  const logins = [];
  /** @type {unknown[]} */
  const events = [];
  /** @type {string[]} */
  const reconnects = [];
  const wsClient = createWsClient({
    url: URL,
    api: { fetchToken },
    backoff,
    WebSocketImpl,
    timers: globalThis,
    onStatus: (status) => {
      statuses.push(status);
    },
    navigateToLogin: () => {
      logins.push("login");
    },
    onEvent: (event) => {
      events.push(event);
    },
    onReconnected: () => {
      reconnects.push("reconnected");
    },
  });

  return { wsClient, sockets, backoff, statuses, logins, events, reconnects };
};

describe("ws-client", () => {
  it("UNIT-JS-WS-01: start берёт токен, открывает соединение, после open шлёт auth; до auth.ok статус reconnecting", async () => {
    const { wsClient, sockets, statuses } = client(vi.fn().mockResolvedValue("token-1"));

    wsClient.start();
    await vi.advanceTimersByTimeAsync(0);
    sockets[0]?.open();

    expect(sockets.map((socket) => [socket.url, socket.sent])).toStrictEqual([
      [URL, [{ type: "auth", token: "token-1" }]],
    ]);
    expect(statuses).toStrictEqual(["reconnecting"]);
  });

  it("UNIT-JS-WS-02: auth.ok → статус online, backoff сброшен, ping каждые 25 секунд", async () => {
    const { wsClient, sockets, backoff, statuses } = client(vi.fn().mockResolvedValue("token-1"));
    backoff.next();
    wsClient.start();
    await vi.advanceTimersByTimeAsync(0);
    sockets[0]?.open();

    sockets[0]?.receive({ type: "auth.ok" });
    await vi.advanceTimersByTimeAsync(50_000);

    expect(statuses).toStrictEqual(["reconnecting", "online"]);
    expect(backoff.next()).toBe(1000);
    expect(sockets[0]?.sent).toStrictEqual([{ type: "auth", token: "token-1" }, { type: "ping" }, { type: "ping" }]);
  });

  it.each(['{"type":"pong"}', '{"type":"auth"}', '{"kind":"auth.ok"}', "null", '"auth.ok"', '{"type":'])(
    "UNIT-JS-WS-02: сообщение не auth.ok (%s) статус и соединение не меняет",
    async (text) => {
      const { wsClient, sockets, statuses } = client(vi.fn().mockResolvedValue("token-1"));
      wsClient.start();
      await vi.advanceTimersByTimeAsync(0);
      sockets[0]?.open();

      sockets[0]?.receiveRaw(text);

      expect([statuses, sockets.length, sockets[0]?.closed]).toStrictEqual([["reconnecting"], 1, false]);
    },
  );

  it.each(["comment.created", "comment.updated", "comment.deleted", "comment.restored"])(
    "UNIT-JS-WS-03: событие %s → onEvent(event)",
    async (type) => {
      const event = { type, comment: { id: 42, author: "Ян", text: "Комментарий" } };
      const { wsClient, sockets, events } = client(vi.fn().mockResolvedValue("token-1"));
      wsClient.start();
      await vi.advanceTimersByTimeAsync(0);
      sockets[0]?.open();
      sockets[0]?.receive({ type: "auth.ok" });

      sockets[0]?.receive(event);

      expect(events).toStrictEqual([event]);
    },
  );

  it.each([
    '{"type":"pong"}',
    '{"type":"auth.ok"}',
    '{"type":"comment.liked","comment":{}}',
    '{"comment":{}}',
    '{"type":"comment.created"}',
    '{"type":"comment.created","comment":null}',
    '{"type":"comment.created","comment":"42"}',
    '"comment.created"',
    "null",
    '{"type":',
  ])("UNIT-JS-WS-03: %s событием не считается", async (text) => {
    const { wsClient, sockets, events } = client(vi.fn().mockResolvedValue("token-1"));
    wsClient.start();
    await vi.advanceTimersByTimeAsync(0);
    sockets[0]?.open();
    sockets[0]?.receive({ type: "auth.ok" });

    sockets[0]?.receiveRaw(text);

    expect(events).toStrictEqual([]);
  });

  it("UNIT-JS-WS-08: первое подключение onReconnected не вызывает", async () => {
    const { wsClient, sockets, reconnects } = client(vi.fn().mockResolvedValue("token-1"));
    wsClient.start();
    await vi.advanceTimersByTimeAsync(0);
    sockets[0]?.open();

    sockets[0]?.receive({ type: "auth.ok" });

    expect(reconnects).toStrictEqual([]);
  });

  it("UNIT-JS-WS-08: auth.ok после переподключения → onReconnected() один раз", async () => {
    const { wsClient, sockets, reconnects } = client(vi.fn().mockResolvedValue("token-1"));
    wsClient.start();
    await vi.advanceTimersByTimeAsync(0);
    sockets[0]?.open();
    sockets[0]?.receive({ type: "auth.ok" });
    sockets[0]?.drop(1006);
    await vi.advanceTimersByTimeAsync(1000);
    sockets[1]?.open();

    sockets[1]?.receive({ type: "auth.ok" });

    expect(reconnects).toStrictEqual(["reconnected"]);
  });

  it.each([4400, 4401, 1011, 1001, 1006])(
    "UNIT-JS-WS-05: закрытие с кодом %i → reconnecting, до конца паузы backoff нового соединения нет",
    async (code) => {
      const { wsClient, sockets, statuses } = client(vi.fn().mockResolvedValue("token-1"));
      wsClient.start();
      await vi.advanceTimersByTimeAsync(0);
      sockets[0]?.open();
      sockets[0]?.receive({ type: "auth.ok" });

      sockets[0]?.drop(code);
      await vi.advanceTimersByTimeAsync(999);

      expect([statuses, sockets.length]).toStrictEqual([["reconnecting", "online", "reconnecting"], 1]);
    },
  );

  it.each([4400, 4401, 1011, 1001, 1006])(
    "UNIT-JS-WS-05: закрытие с кодом %i → после паузы backoff новое соединение с новым токеном",
    async (code) => {
      const { wsClient, sockets } = client(vi.fn().mockResolvedValueOnce("token-1").mockResolvedValueOnce("token-2"));
      wsClient.start();
      await vi.advanceTimersByTimeAsync(0);
      sockets[0]?.open();
      sockets[0]?.receive({ type: "auth.ok" });

      sockets[0]?.drop(code);
      await vi.advanceTimersByTimeAsync(1000);
      sockets[1]?.open();

      expect(sockets.map((socket) => socket.sent)).toStrictEqual([
        [{ type: "auth", token: "token-1" }],
        [{ type: "auth", token: "token-2" }],
      ]);
    },
  );

  it("UNIT-JS-WS-05: после разрыва ping старого соединения больше не уходит", async () => {
    const { wsClient, sockets } = client(vi.fn().mockResolvedValue("token-1"));
    wsClient.start();
    await vi.advanceTimersByTimeAsync(0);
    sockets[0]?.open();
    sockets[0]?.receive({ type: "auth.ok" });

    sockets[0]?.drop(1006);
    await vi.advanceTimersByTimeAsync(25_000);

    expect(sockets[0]?.sent).toStrictEqual([{ type: "auth", token: "token-1" }]);
  });

  it("UNIT-JS-WS-06: новый токен не выдали (UnauthorizedError) → navigateToLogin, соединения нет", async () => {
    const { wsClient, sockets, logins } = client(
      vi.fn().mockRejectedValue(new UnauthorizedError("сессия закончилась")),
    );

    wsClient.start();
    await vi.advanceTimersByTimeAsync(60_000);

    expect(logins).toStrictEqual(["login"]);
    expect(sockets).toStrictEqual([]);
  });

  it("UNIT-JS-WS-07: UnavailableError при получении токена → reconnecting, до конца двух пауз backoff соединения нет", async () => {
    const { wsClient, sockets, statuses, logins } = client(
      vi
        .fn()
        .mockRejectedValueOnce(new UnavailableError("503"))
        .mockRejectedValueOnce(new UnavailableError("503"))
        .mockResolvedValue("token-1"),
    );

    wsClient.start();
    await vi.advanceTimersByTimeAsync(2999);

    expect([sockets.length, statuses, logins]).toStrictEqual([0, ["reconnecting", "reconnecting", "reconnecting"], []]);
  });

  it("UNIT-JS-WS-07: UnavailableError при получении токена → после пауз backoff (1 с, затем 2 с) новая попытка открывает соединение", async () => {
    const { wsClient, sockets } = client(
      vi
        .fn()
        .mockRejectedValueOnce(new UnavailableError("503"))
        .mockRejectedValueOnce(new UnavailableError("503"))
        .mockResolvedValue("token-1"),
    );

    wsClient.start();
    await vi.advanceTimersByTimeAsync(3000);

    expect(sockets.map((socket) => socket.url)).toStrictEqual([URL]);
  });

  it("UNIT-JS-WS-09: stop закрывает соединение, отменяет ping и переподключение", async () => {
    const { wsClient, sockets } = client(vi.fn().mockResolvedValue("token-1"));
    wsClient.start();
    await vi.advanceTimersByTimeAsync(0);
    sockets[0]?.open();
    sockets[0]?.receive({ type: "auth.ok" });

    wsClient.stop();
    sockets[0]?.drop(1000);
    await vi.advanceTimersByTimeAsync(60_000);

    expect(sockets.map((socket) => [socket.closed, socket.sent.length])).toStrictEqual([[true, 1]]);
  });

  it("UNIT-JS-WS-09: stop во время запроса токена — соединение после ответа не открывается", async () => {
    const { promise: token, resolve: issueToken } = Promise.withResolvers();
    const { wsClient, sockets } = client(vi.fn().mockReturnValue(token));
    wsClient.start();

    wsClient.stop();
    issueToken("token-1");
    await vi.advanceTimersByTimeAsync(60_000);

    expect(sockets).toStrictEqual([]);
  });

  it("UNIT-JS-WS-09: stop во время запроса токена, который не выдали (503), — новых запросов токена нет", async () => {
    const { promise: token, reject: refuseToken } = Promise.withResolvers();
    const fetchToken = vi.fn().mockReturnValueOnce(token).mockResolvedValue("token-2");
    const { wsClient } = client(fetchToken);
    wsClient.start();

    wsClient.stop();
    refuseToken(new UnavailableError("503"));
    await vi.advanceTimersByTimeAsync(60_000);

    expect(fetchToken.mock.calls).toStrictEqual([[]]);
  });

  it("UNIT-JS-WS-09: stop во время паузы backoff отменяет запланированную попытку", async () => {
    const { wsClient, sockets } = client(vi.fn().mockResolvedValue("token-1"));
    wsClient.start();
    await vi.advanceTimersByTimeAsync(0);
    sockets[0]?.drop(1006);

    wsClient.stop();
    await vi.advanceTimersByTimeAsync(60_000);

    expect(sockets).toHaveLength(1);
  });
});
