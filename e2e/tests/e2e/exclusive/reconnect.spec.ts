import { listRow, openAdminPage } from "../../../support/admin.ts";
import { admin } from "../../../support/factories.ts";
import { message } from "../../../support/messages.ts";
import { uniqueTag } from "../../../support/tag.ts";
import { expect, test } from "../../../support/test.ts";
import { interceptAdminWs } from "../../../support/ws-break.ts";

test("E2E-08: после разрыва WS клиент переподключается и подхватывает комментарий, созданный во время разрыва", async ({
  page: admin1Page,
  guest,
  db,
  faker,
}) => {
  const missedComment = { name: faker.person.firstName(), text: `${faker.lorem.sentence()} ${uniqueTag(faker)}` };
  const indicator = admin1Page.getByRole("status", { name: message("ws.status.label") });
  const ws = await interceptAdminWs(admin1Page);
  await openAdminPage(admin1Page, await admin(db, faker));

  await test.step("1. Соединение рвётся кодом 1006 → «Переподключение…»; пока клиент не подключён, гость отправляет комментарий", async () => {
    await ws.breakConnection();

    await expect(indicator).toHaveText(message("ws.status.reconnecting"));
    await guest.goto("/");
    await guest.getByRole("textbox", { name: message("form.name") }).fill(missedComment.name);
    await guest.getByRole("textbox", { name: message("form.text") }).fill(missedComment.text);
    await guest.getByRole("button", { name: message("form.submit") }).click();
    await expect(guest.getByText(message("comment.created"))).toBeVisible();
  });

  await test.step("2. Переподключение отпущено → «Онлайн»", async () => {
    ws.releaseReconnect();

    await expect(indicator).toHaveText(message("ws.status.online"));
  });

  await test.step("3. Комментарий, созданный во время разрыва, есть в списке без перезагрузки страницы", async () => {
    await expect(listRow(admin1Page, missedComment.text)).toBeVisible();
  });
});
