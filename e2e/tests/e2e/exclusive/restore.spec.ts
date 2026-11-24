import { listRow, openAdminPage } from "../../../support/admin.ts";
import { admin, comment } from "../../../support/factories.ts";
import { message } from "../../../support/messages.ts";
import { expect, test } from "../../../support/test.ts";

test("E2E-04: восстановление видно второму админу и гостю", async ({
  page: admin1Page,
  admin2Page,
  guest,
  db,
  faker,
}) => {
  const admin1 = await admin(db, faker);
  const admin2 = await admin(db, faker);
  // Фабрика ставит даты по порядку создания: восстановленный встаёт между старшим и младшим.
  const olderComment = await comment(db, faker);
  const restoredComment = await comment(db, faker, { deletedBy: admin2.id });
  const newerComment = await comment(db, faker);
  const deletedByAdmin2 = message("admin.audit.deleted").replace("{login}", admin2.username);
  await openAdminPage(admin1Page, admin1, "/?status=deleted");
  await openAdminPage(admin2Page, admin2);

  await test.step("1. Админ 1 восстанавливает комментарий → он пропал из «Удалённых» у Админа 1", async () => {
    const dialogs: string[] = [];
    admin1Page.once("dialog", (dialog) => {
      dialogs.push(dialog.message());
      void dialog.accept();
    });

    await listRow(admin1Page, restoredComment.text)
      .getByRole("button", { name: message("admin.action.restore") })
      .click();

    await expect(admin1Page.getByText(message("admin.restored"))).toBeVisible();
    expect(dialogs).toStrictEqual([message("admin.confirm.restore")]);
    await expect(admin1Page.getByRole("link", { name: message("admin.tab.deleted") })).toHaveAttribute(
      "aria-current",
      "page",
    );
    await expect(listRow(admin1Page, restoredComment.text)).toHaveCount(0);
  });

  await test.step("2. У Админа 2 комментарий вживую появился в «Активных» между младшим и старшим, без «удалил»", async () => {
    const rows = admin2Page.getByRole("table").getByRole("row");

    await expect(rows.nth(1)).toContainText(newerComment.text);
    await expect(rows.nth(2)).toContainText(restoredComment.text);
    await expect(rows.nth(3)).toContainText(olderComment.text);
    await expect(rows.nth(2)).not.toContainText(deletedByAdmin2);
  });

  await test.step("3. Гость перезагружает ленту → комментарий снова между младшим и старшим, с прежним текстом", async () => {
    const items = guest.getByRole("region", { name: message("feed.title") }).getByRole("listitem");

    await guest.goto("/");

    await expect(items.nth(0)).toContainText(newerComment.text);
    await expect(items.nth(1)).toContainText(restoredComment.text);
    await expect(items.nth(2)).toContainText(olderComment.text);
  });
});
