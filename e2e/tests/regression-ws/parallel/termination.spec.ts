import { blockAdmin, changeAdminPassword } from "../../../support/accounts.ts";
import { issueWsToken, logOut } from "../../../support/admin.ts";
import { ADMIN_BLOCKED, admin, setAdminStatus } from "../../../support/factories.ts";
import { expect, test } from "../../../support/test.ts";

const UNAUTHORIZED = 4401;
const SESSION_ENDED = 4403;

test("REG-WS-08: logout сессии 1 → закрыто только соединение сессии 1, с кодом 4403", async ({
  adminAccount,
  adminApi,
  signIn,
  ws,
  db,
  faker,
}) => {
  const session2 = await signIn(adminAccount);
  const boris = await signIn(await admin(db, faker));
  const annaSession1 = await ws.openAuthenticated(await issueWsToken(adminApi));
  const annaSession2 = await ws.openAuthenticated(await issueWsToken(session2));
  const borisSocket = await ws.openAuthenticated(await issueWsToken(boris));

  await logOut(adminApi);

  expect([await annaSession1.closeCode(), await annaSession2.isAlive(), await borisSocket.isAlive()]).toStrictEqual([
    SESSION_ENDED,
    true,
    true,
  ]);
});

test("REG-WS-09: А блокируют → оба соединения А закрыты 4403, соединение Б живо", async ({
  adminAccount,
  adminApi,
  signIn,
  ws,
  db,
  redis,
  faker,
}) => {
  const session2 = await signIn(adminAccount);
  const boris = await signIn(await admin(db, faker));
  const annaSession1 = await ws.openAuthenticated(await issueWsToken(adminApi));
  const annaSession2 = await ws.openAuthenticated(await issueWsToken(session2));
  const borisSocket = await ws.openAuthenticated(await issueWsToken(boris));

  await blockAdmin(db, redis, faker, adminAccount);

  expect([await annaSession1.closeCode(), await annaSession2.closeCode(), await borisSocket.isAlive()]).toStrictEqual([
    SESSION_ENDED,
    SESSION_ENDED,
    true,
  ]);
});

test("REG-WS-11: токен выдан, затем logout этой сессии → auth этим токеном закрыт 4401, auth.ok не приходит", async ({
  adminApi,
  ws,
}) => {
  const token = await issueWsToken(adminApi);
  await logOut(adminApi);
  const socket = await ws.open();

  socket.send({ type: "auth", token });

  expect([await socket.messagesUntilClose(), await socket.closeCode()]).toStrictEqual([[], UNAUTHORIZED]);
});

test("REG-WS-11: токен выдан, затем смена пароля админа → auth этим токеном закрыт 4401, auth.ok не приходит", async ({
  adminAccount,
  adminApi,
  ws,
  db,
  redis,
  faker,
}) => {
  const token = await issueWsToken(adminApi);
  await changeAdminPassword(db, redis, faker, adminAccount);
  const socket = await ws.open();

  socket.send({ type: "auth", token });

  expect([await socket.messagesUntilClose(), await socket.closeCode()]).toStrictEqual([[], UNAUTHORIZED]);
});

test("REG-WS-12: А заблокирован в БД, а access.revoked до WS не дошёл → на следующий ping соединение закрыто 4403, pong не приходит", async ({
  adminAccount,
  adminApi,
  ws,
  db,
}) => {
  const socket = await ws.openAuthenticated(await issueWsToken(adminApi));
  await setAdminStatus(db, adminAccount.id, ADMIN_BLOCKED);

  socket.send({ type: "ping" });

  expect([await socket.messagesUntilClose(), await socket.closeCode()]).toStrictEqual([[], SESSION_ENDED]);
});
