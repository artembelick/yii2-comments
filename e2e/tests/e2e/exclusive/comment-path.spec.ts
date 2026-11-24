import { listRow } from "../../../support/admin.ts";
import { requireEnv } from "../../../support/env.ts";
import { admin, comment } from "../../../support/factories.ts";
import { message } from "../../../support/messages.ts";
import { uniqueTag } from "../../../support/tag.ts";
import { expect, test } from "../../../support/test.ts";

test("E2E-01: путь комментария от формы до админки", async ({ page: admin1Page, guest, db, faker }) => {
  const admin1 = await admin(db, faker);
  const oldComment = await comment(db, faker);
  const guestComment = { name: faker.person.firstName(), text: `${faker.lorem.sentence()} ${uniqueTag(faker)}` };
  const feed = guest.getByRole("region", { name: message("feed.title") });

  await test.step("1. Админ 1 открывает админку без входа → страница логина (P1)", async () => {
    await admin1Page.goto(requireEnv("ADMIN_URL"));

    await expect(admin1Page).toHaveURL(`${requireEnv("ADMIN_URL")}/login`);
    await expect(admin1Page.getByRole("heading", { name: message("login.title") })).toBeVisible();
  });

  await test.step("2. Админ 1 входит → список «Активные», в нём старый комментарий (P2)", async () => {
    await admin1Page.getByLabel(message("login.username")).fill(admin1.username);
    await admin1Page.getByLabel(message("login.password")).fill(admin1.password);
    await admin1Page.getByRole("button", { name: message("login.submit") }).click();

    await expect(admin1Page.getByRole("heading", { name: message("admin.title") })).toBeVisible();
    await expect(admin1Page.getByRole("link", { name: message("admin.tab.active") })).toHaveAttribute(
      "aria-current",
      "page",
    );
    await expect(listRow(admin1Page, oldComment.text)).toContainText(oldComment.author);
  });

  await test.step("3. Гость в своём контексте открывает публичную часть, видит форму и старый комментарий (P4)", async () => {
    await guest.goto("/");

    await expect(guest.getByRole("textbox", { name: message("form.name") })).toBeVisible();
    await expect(feed.getByText(oldComment.text)).toBeVisible();
  });

  await test.step("4. Гость отправляет свой комментарий → флэш, он первым в ленте, старый под ним (P5–P7)", async () => {
    await guest.getByRole("textbox", { name: message("form.name") }).fill(guestComment.name);
    await guest.getByRole("textbox", { name: message("form.text") }).fill(guestComment.text);
    await guest.getByRole("button", { name: message("form.submit") }).click();

    await expect(guest.getByText(message("comment.created"))).toBeVisible();
    await expect(feed.getByRole("listitem").nth(0)).toContainText(guestComment.text);
    await expect(feed.getByRole("listitem").nth(1)).toContainText(oldComment.text);
  });

  await test.step("5. У Админа 1 индикатор «онлайн» (P3)", async () => {
    await expect(admin1Page.getByRole("status", { name: message("ws.status.label") })).toHaveText(
      message("ws.status.online"),
    );
  });

  await test.step("6. У Админа 1 комментарий гостя появился первой строкой без перезагрузки и подсвечен (P8–P10)", async () => {
    const firstRow = admin1Page.getByRole("table").getByRole("row").nth(1);

    await expect(firstRow).toContainText(guestComment.text);
    await expect(firstRow).toHaveAttribute("data-highlighted", "true");
  });
});
