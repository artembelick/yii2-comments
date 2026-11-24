import { listRow, openAdminPage } from "../../../support/admin.ts";
import { requireEnv } from "../../../support/env.ts";
import { admin } from "../../../support/factories.ts";
import { message } from "../../../support/messages.ts";
import { uniqueTag } from "../../../support/tag.ts";
import { expect, test } from "../../../support/test.ts";

test("E2E-06: logout закрывает WS только своей сессии", async ({ page: admin1Page, admin2Page, guest, db, faker }) => {
  const commentAfterLogout = { name: faker.person.firstName(), text: `${faker.lorem.sentence()} ${uniqueTag(faker)}` };
  const loginUrl = `${requireEnv("ADMIN_URL")}/login`;
  const online = message("ws.status.online");
  await openAdminPage(admin1Page, await admin(db, faker));
  const admin1SecondTab = await admin1Page.context().newPage();
  await admin1SecondTab.goto(requireEnv("ADMIN_URL"));
  await expect(admin1SecondTab.getByRole("status", { name: message("ws.status.label") })).toHaveText(online);
  await openAdminPage(admin2Page, await admin(db, faker));

  await test.step("1. Админ 1 выходит в первой вкладке", async () => {
    await admin1Page.getByRole("button", { name: message("admin.logout") }).click();

    await expect(admin1Page).toHaveURL(loginUrl);
  });

  await test.step("2. Во второй вкладке Админа 1 соединение закрыто, вкладка ушла на логин", async () => {
    await expect(admin1SecondTab).toHaveURL(loginUrl);
  });

  await test.step("3. У Админа 2 индикатор остаётся «онлайн»", async () => {
    await expect(admin2Page.getByRole("status", { name: message("ws.status.label") })).toHaveText(online);
  });

  await test.step("4. Гость отправляет комментарий → у Админа 2 он появляется вживую", async () => {
    await guest.goto("/");
    await guest.getByRole("textbox", { name: message("form.name") }).fill(commentAfterLogout.name);
    await guest.getByRole("textbox", { name: message("form.text") }).fill(commentAfterLogout.text);
    await guest.getByRole("button", { name: message("form.submit") }).click();

    await expect(listRow(admin2Page, commentAfterLogout.text)).toBeVisible();
  });
});
