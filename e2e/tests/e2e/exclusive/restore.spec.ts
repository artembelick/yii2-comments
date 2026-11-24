import { openAdminPage } from "../../../support/admin.ts";
import { admin, comment } from "../../../support/factories.ts";
import { message } from "../../../support/messages.ts";
import { expect, test } from "../../../support/test.ts";

test("E2E-04: восстановление видно второму админу и гостю", async ({ page, otherAdmin, guest, db, faker }) => {
  const adminA = await admin(db, faker);
  const adminB = await admin(db, faker);
  // Даты создания по порядку: К0 старше К1, К2 новее. Восстановленный К1 встаёт между ними.
  const k0 = await comment(db, faker);
  const k1 = await comment(db, faker, { deletedBy: adminB.id });
  const k2 = await comment(db, faker);
  const deletedByB = message("admin.audit.deleted").replace("{login}", adminB.username);
  await openAdminPage(page, adminA, "/?status=deleted");
  await openAdminPage(otherAdmin, adminB);

  await test.step("1. А восстанавливает К1 → К1 пропал из «Удалённых» у А", async () => {
    const dialogs: string[] = [];
    page.once("dialog", (dialog) => {
      dialogs.push(dialog.message());
      void dialog.accept();
    });

    await page
      .getByRole("row")
      .filter({ hasText: k1.text })
      .getByRole("button", { name: message("admin.action.restore") })
      .click();

    await expect(page.getByText(message("admin.restored"))).toBeVisible();
    expect(dialogs).toStrictEqual([message("admin.confirm.restore")]);
    await expect(page.getByRole("link", { name: message("admin.tab.deleted") })).toHaveAttribute(
      "aria-current",
      "page",
    );
    await expect(page.getByRole("row").filter({ hasText: k1.text })).toHaveCount(0);
  });

  await test.step("2. У Б К1 вживую появился в «Активных» между К2 и К0, без «удалил»", async () => {
    const rows = otherAdmin.getByRole("row");

    await expect(rows.nth(2)).toContainText(k1.text);
    await expect(rows.nth(1)).toContainText(k2.text);
    await expect(rows.nth(3)).toContainText(k0.text);
    await expect(rows.nth(2)).not.toContainText(deletedByB);
  });

  await test.step("3. Гость перезагружает ленту → К1 снова на своём месте с прежним текстом", async () => {
    const items = guest.getByRole("region", { name: message("feed.title") }).getByRole("listitem");

    await guest.goto("/");

    await expect(items.nth(0)).toContainText(k2.text);
    await expect(items.nth(1)).toContainText(k1.text);
    await expect(items.nth(2)).toContainText(k0.text);
  });
});
