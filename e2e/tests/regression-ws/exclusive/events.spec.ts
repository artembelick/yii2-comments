import { issueWsToken, submitAdminForm } from "../../../support/admin.ts";
import { comment, uniqueTag } from "../../../support/factories.ts";
import type { Comment, CommentOverrides } from "../../../support/factories.ts";
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

interface Change {
  /** Комментарий-предусловие: тот, который действие изменит. */
  precondition: (adminId: number) => CommentOverrides;
  /** Поля формы действия: текст шлёт только правка. */
  fields: (newText: string) => Record<string, string>;
  /** Ожидаемый комментарий в событии, кроме дат создания и правки. */
  expected: (target: Comment, newText: string, login: string) => Record<string, unknown>;
}

const CHANGES: (Change & { name: string; action: string; type: string })[] = [
  {
    name: "правка",
    action: "edit",
    type: "comment.updated",
    precondition: () => ({}),
    fields: (newText) => ({ text: newText }),
    expected: (target, newText, login) => ({
      ...target,
      text: newText,
      status: "active",
      updatedAt: expect.any(String),
      updatedBy: login,
      deletedBy: null,
    }),
  },
  {
    name: "удаление",
    action: "delete",
    type: "comment.deleted",
    precondition: () => ({}),
    fields: () => ({}),
    expected: (target, _newText, login) => ({
      ...target,
      status: "deleted",
      updatedAt: null,
      updatedBy: null,
      deletedBy: login,
    }),
  },
  {
    name: "восстановление",
    action: "restore",
    type: "comment.restored",
    precondition: (adminId) => ({ deletedBy: adminId }),
    fields: () => ({}),
    expected: (target) => ({ ...target, status: "active", updatedAt: null, updatedBy: null, deletedBy: null }),
  },
];

CHANGES.forEach(({ name, action, type, precondition, fields, expected }) => {
  test(`REG-WS-07: авторизованное соединение получает ровно одно событие ${type} с полным комментарием (${name})`, async ({
    adminAccount,
    adminApi,
    ws,
    db,
    faker,
  }) => {
    const target = await comment(db, faker, precondition(adminAccount.id));
    const newText = `Новый текст ${uniqueTag(faker)}`;
    const socket = await ws.openAuthenticated(await issueWsToken(adminApi));

    await submitAdminForm(adminApi, `/comments/${target.id}/${action}`, fields(newText));

    expect(await socket.messagesUntilPong()).toStrictEqual([
      { type, comment: { ...expected(target, newText, adminAccount.username), createdAt: expect.any(String) } },
    ]);
  });
});
