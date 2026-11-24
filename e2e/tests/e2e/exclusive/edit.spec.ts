import { listRow, openAdminPage } from "../../../support/admin.ts";
import { admin, comment, uniqueTag } from "../../../support/factories.ts";
import { message } from "../../../support/messages.ts";
import { expect, test } from "../../../support/test.ts";

test("E2E-02: правка видна второму админу и гостю", async ({ page: admin1Page, admin2Page, guest, db, faker }) => {
  const admin1 = await admin(db, faker);
  const admin2 = await admin(db, faker);
  const editedComment = await comment(db, faker);
  const newText = `${faker.lorem.sentence()} ${uniqueTag(faker)}`;
  const updatedByAdmin1 = message("admin.audit.updated").replace("{login}", admin1.username);
  await openAdminPage(admin1Page, admin1);
  await openAdminPage(admin2Page, admin2);

  // Текст меняется, поэтому строку ищем по имени автора: в нём тоже уникальная метка.
  await test.step("1. Админ 1 правит текст → флэш, в его списке новый текст и «изменил: <логин Админа 1>»", async () => {
    await listRow(admin1Page, editedComment.author)
      .getByRole("link", { name: message("admin.action.edit") })
      .click();
    await admin1Page.getByLabel(message("admin.edit.text")).fill(newText);
    await admin1Page.getByRole("button", { name: message("admin.edit.save") }).click();

    await expect(admin1Page.getByText(message("admin.updated"))).toBeVisible();
    await expect(listRow(admin1Page, editedComment.author)).toContainText(newText);
    await expect(listRow(admin1Page, editedComment.author)).toContainText(updatedByAdmin1);
  });

  await test.step("2. У Админа 2 строка обновилась вживую: новый текст и «изменил: <логин Админа 1>»", async () => {
    await expect(listRow(admin2Page, editedComment.author)).toContainText(newText);
    await expect(listRow(admin2Page, editedComment.author)).toContainText(updatedByAdmin1);
  });

  await test.step("3. Гость перезагружает ленту → новый текст без пометок о правке", async () => {
    const feed = guest.getByRole("region", { name: message("feed.title") });

    await guest.goto("/");

    await expect(feed.getByRole("listitem").filter({ hasText: editedComment.author })).toContainText(newText);
    await expect(feed).not.toContainText(updatedByAdmin1);
  });
});
