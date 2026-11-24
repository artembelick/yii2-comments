import { logIn } from "../../../support/admin.ts";
import { requireEnv } from "../../../support/env.ts";
import { admin, comments, uniqueTag } from "../../../support/factories.ts";
import { message, plural } from "../../../support/messages.ts";
import { expect, test } from "../../../support/test.ts";

const PRECONDITION_COMMENTS = 25;

test("E2E-07: не на первой странице новый комментарий даёт баннер «N новых», а не строку", async ({
  page,
  guest,
  db,
  faker,
}) => {
  await comments(db, faker, PRECONDITION_COMMENTS);
  const k26 = { name: faker.person.firstName(), text: `${faker.lorem.sentence()} ${uniqueTag(faker)}` };
  const adminList = page.getByRole("table");
  const banner = page.getByRole("link", { name: plural("admin.banner.new", 1) });
  await logIn(page.request, await admin(db, faker));
  await page.goto(`${requireEnv("ADMIN_URL")}/?status=active&page=2`);
  await expect(page.getByRole("status", { name: message("ws.status.label") })).toHaveText(message("ws.status.online"));
  const rowsBefore = await adminList.getByRole("row").allTextContents();

  await test.step("1–2. Гость отправляет К26 → у А баннер «1 новый комментарий», строки второй страницы прежние", async () => {
    await guest.goto("/");
    await guest.getByRole("textbox", { name: message("form.name") }).fill(k26.name);
    await guest.getByRole("textbox", { name: message("form.text") }).fill(k26.text);
    await guest.getByRole("button", { name: message("form.submit") }).click();

    await expect(banner).toBeVisible();
    expect(await adminList.getByRole("row").allTextContents()).toStrictEqual(rowsBefore);
  });

  await test.step("3. А нажимает на баннер → первая страница, К26 первым", async () => {
    await banner.click();

    await expect(adminList.getByRole("row").nth(1)).toContainText(k26.text);
  });
});
