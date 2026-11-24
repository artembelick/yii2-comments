import { logIn } from "../../../support/admin.ts";
import { requireEnv } from "../../../support/env.ts";
import { admin, comments } from "../../../support/factories.ts";
import { message, plural } from "../../../support/messages.ts";
import { uniqueTag } from "../../../support/tag.ts";
import { expect, test } from "../../../support/test.ts";

const PRECONDITION_COMMENTS = 25;

test("E2E-07: не на первой странице новый комментарий даёт баннер «N новых», а не строку", async ({
  page: admin1Page,
  guest,
  db,
  faker,
}) => {
  await comments(db, faker, PRECONDITION_COMMENTS);
  const newComment = { name: faker.person.firstName(), text: `${faker.lorem.sentence()} ${uniqueTag(faker)}` };
  const admin1List = admin1Page.getByRole("table");
  const banner = admin1Page.getByRole("link", { name: plural("admin.banner.new", 1) });
  await logIn(admin1Page.request, await admin(db, faker));
  await admin1Page.goto(`${requireEnv("ADMIN_URL")}/?status=active&page=2`);
  await expect(admin1Page.getByRole("status", { name: message("ws.status.label") })).toHaveText(
    message("ws.status.online"),
  );
  const rowsBefore = await admin1List.getByRole("row").allTextContents();

  await test.step("1–2. Гость отправляет новый комментарий → у Админа 1 баннер «1 новый комментарий», строки второй страницы прежние", async () => {
    await guest.goto("/");
    await guest.getByRole("textbox", { name: message("form.name") }).fill(newComment.name);
    await guest.getByRole("textbox", { name: message("form.text") }).fill(newComment.text);
    await guest.getByRole("button", { name: message("form.submit") }).click();

    await expect(banner).toBeVisible();
    expect(await admin1List.getByRole("row").allTextContents()).toStrictEqual(rowsBefore);
  });

  await test.step("3. Админ 1 нажимает на баннер → первая страница, новый комментарий первым", async () => {
    await banner.click();

    await expect(admin1List.getByRole("row").nth(1)).toContainText(newComment.text);
  });
});
