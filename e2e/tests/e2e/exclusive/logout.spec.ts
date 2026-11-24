import { openAdminPage } from "../../../support/admin.ts";
import { requireEnv } from "../../../support/env.ts";
import { admin, uniqueTag } from "../../../support/factories.ts";
import { message } from "../../../support/messages.ts";
import { expect, test } from "../../../support/test.ts";

test("E2E-06: logout закрывает WS только своей сессии", async ({ page, otherAdmin, guest, db, faker }) => {
  const k2 = { name: faker.person.firstName(), text: `${faker.lorem.sentence()} ${uniqueTag(faker)}` };
  const loginUrl = `${requireEnv("ADMIN_URL")}/login`;
  const online = message("ws.status.online");
  await openAdminPage(page, await admin(db, faker));
  const secondTab = await page.context().newPage();
  await secondTab.goto(requireEnv("ADMIN_URL"));
  await expect(secondTab.getByRole("status", { name: message("ws.status.label") })).toHaveText(online);
  await openAdminPage(otherAdmin, await admin(db, faker));

  await test.step("1. А выходит в первой вкладке", async () => {
    await page.getByRole("button", { name: message("admin.logout") }).click();

    await expect(page).toHaveURL(loginUrl);
  });

  await test.step("2. Во второй вкладке А соединение закрыто, вкладка ушла на логин", async () => {
    await expect(secondTab).toHaveURL(loginUrl);
  });

  await test.step("3. У Б индикатор остаётся «онлайн»", async () => {
    await expect(otherAdmin.getByRole("status", { name: message("ws.status.label") })).toHaveText(online);
  });

  await test.step("4. Гость отправляет К2 → у Б он появляется вживую", async () => {
    await guest.goto("/");
    await guest.getByRole("textbox", { name: message("form.name") }).fill(k2.name);
    await guest.getByRole("textbox", { name: message("form.text") }).fill(k2.text);
    await guest.getByRole("button", { name: message("form.submit") }).click();

    await expect(otherAdmin.getByRole("row").filter({ hasText: k2.text })).toBeVisible();
  });
});
