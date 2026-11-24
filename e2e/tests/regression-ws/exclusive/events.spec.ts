import { issueWsToken } from "../../../support/admin.ts";
import { uniqueTag } from "../../../support/factories.ts";
import { postComment } from "../../../support/guest.ts";
import { expect, test } from "../../../support/test.ts";

const UNAUTHORIZED = 4401;

test("REG-WS-06: открытое, но неавторизованное соединение событий о новом комментарии не получает", async ({
  adminApi,
  guestApi,
  ws,
  faker,
}) => {
  const watcher = await ws.openAuthenticated(await issueWsToken(adminApi));
  const stranger = await ws.open();

  await postComment(guestApi, { name: "Ян", text: `Комментарий ${uniqueTag(faker)}` });

  // Авторизованное соединение событие получило: рассылка прошла, неавторизованному его просто не отправили.
  expect(await watcher.nextMessage()).toMatchObject({ type: "comment.created" });
  expect(await stranger.messagesUntilClose()).toStrictEqual([]);
  expect(await stranger.closeCode()).toBe(UNAUTHORIZED);
});

test("REG-WS-07: авторизованное соединение получает ровно одно событие comment.created с полным комментарием", async ({
  adminApi,
  guestApi,
  ws,
  faker,
}) => {
  const text = `Комментарий ${uniqueTag(faker)}`;
  const socket = await ws.openAuthenticated(await issueWsToken(adminApi));

  await postComment(guestApi, { name: "Ян", text });

  expect(await socket.messagesUntilPong()).toStrictEqual([
    {
      type: "comment.created",
      comment: {
        id: expect.any(Number),
        author: "Ян",
        text,
        status: "active",
        createdAt: expect.any(String),
        updatedAt: null,
        updatedBy: null,
        deletedBy: null,
      },
    },
  ]);
});
