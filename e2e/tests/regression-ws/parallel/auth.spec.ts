import { issueWsToken } from "../../../support/admin.ts";
import { expect, test } from "../../../support/test.ts";
import { handshakeStatus } from "../../../support/ws.ts";

const UNAUTHORIZED = 4401;

test("REG-WS-01: handshake с чужим Origin → HTTP 403, соединение не открыто", async () => {
  const foreignOrigin = "http://comments.localhost";

  const status = await handshakeStatus(foreignOrigin);

  expect(status).toBe(403);
});

test("REG-WS-02: нет auth 5 секунд → закрыто с кодом 4401", async ({ ws }) => {
  const socket = await ws.open();

  const code = await socket.closeCode();

  expect(code).toBe(UNAUTHORIZED);
});

[
  { name: "неверный токен", token: "Abcdefghijklmnopqrstuvwxyz0123456789-_ABCDE" },
  { name: "пустой токен", token: "" },
  { name: "токен другого формата", token: "not-a-token" },
].forEach(({ name, token }) => {
  test(`REG-WS-03: ${name} → закрыто 4401`, async ({ ws }) => {
    const socket = await ws.open();

    socket.send({ type: "auth", token });

    expect(await socket.closeCode()).toBe(UNAUTHORIZED);
  });
});

test("REG-WS-04: токен уже использован → второе соединение с тем же токеном закрыто 4401", async ({ adminApi, ws }) => {
  const token = await issueWsToken(adminApi);
  await ws.openAuthenticated(token);
  const second = await ws.open();

  second.send({ type: "auth", token });

  expect(await second.closeCode()).toBe(UNAUTHORIZED);
});

test("REG-WS-04a: второе соединение с тем же токеном отклонено → первое живо, на ping отвечает pong", async ({
  adminApi,
  ws,
}) => {
  const token = await issueWsToken(adminApi);
  const first = await ws.openAuthenticated(token);
  const second = await ws.open();
  second.send({ type: "auth", token });
  await second.closeCode();

  first.send({ type: "ping" });

  expect(await first.nextMessage()).toStrictEqual({ type: "pong" });
});

test("REG-WS-10: ping после авторизации → pong", async ({ adminApi, ws }) => {
  const socket = await ws.openAuthenticated(await issueWsToken(adminApi));

  socket.send({ type: "ping" });

  expect(await socket.nextMessage()).toStrictEqual({ type: "pong" });
});
