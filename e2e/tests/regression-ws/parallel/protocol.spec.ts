import { issueWsToken } from "../../../support/admin.ts";
import { expect, test } from "../../../support/test.ts";

const PROTOCOL_VIOLATION = 4400;
const MAX_MESSAGE_BYTES = 4096;

[
  { name: "не JSON", raw: '{"type":' },
  { name: "неизвестный type", raw: '{"type":"subscribe"}' },
  { name: "ping до авторизации", raw: '{"type":"ping"}' },
  { name: "сообщение больше 4 КБ", raw: JSON.stringify({ type: "ping", padding: "a".repeat(MAX_MESSAGE_BYTES) }) },
].forEach(({ name, raw }) => {
  test(`REG-WS-05: ${name} → закрыто 4400`, async ({ ws }) => {
    const socket = await ws.open();

    socket.sendRaw(raw);

    expect(await socket.closeCode()).toBe(PROTOCOL_VIOLATION);
  });
});

test("REG-WS-05: повторный auth после auth.ok → закрыто 4400", async ({ adminApi, ws }) => {
  const token = await issueWsToken(adminApi);
  const socket = await ws.openAuthenticated(token);

  socket.send({ type: "auth", token });

  expect(await socket.closeCode()).toBe(PROTOCOL_VIOLATION);
});
