import { openAdminPage } from "../../../support/admin.ts";
import { admin, comment } from "../../../support/factories.ts";
import { message } from "../../../support/messages.ts";
import { expect, test } from "../../../support/test.ts";

test("E2E-03: удаление видно второму админу и гостю", async ({ page, otherAdmin, guest, db, faker }) => {
  const adminA = await admin(db, faker);
  const adminB = await admin(db, faker);
  const k2 = await comment(db, faker);
  const k1 = await comment(db, faker);
  const feed = guest.getByRole("region", { name: message("feed.title") });
  await guest.goto("/");
  await expect(feed.getByText(k1.text)).toBeVisible();
  await openAdminPage(page, adminA);
  await openAdminPage(otherAdmin, adminB);

  await test.step("1. А удаляет К1 и подтверждает → К1 пропал из «Активных» у А", async () => {
    const dialogs: string[] = [];
    page.once("dialog", (dialog) => {
      dialogs.push(dialog.message());
      void dialog.accept();
    });

    await page
      .getByRole("row")
      .filter({ hasText: k1.text })
      .getByRole("button", { name: message("admin.action.delete") })
      .click();

    await expect(page.getByText(message("admin.deleted"))).toBeVisible();
    expect(dialogs).toStrictEqual([message("admin.confirm.delete")]);
    await expect(page.getByRole("row").filter({ hasText: k2.text })).toBeVisible();
    await expect(page.getByRole("row").filter({ hasText: k1.text })).toHaveCount(0);
  });

  await test.step("2. У Б К1 вживую ушёл из «Активных», на «Удалённых» он с «удалил: А»", async () => {
    await expect(otherAdmin.getByRole("row").filter({ hasText: k1.text })).toHaveCount(0);
    await expect(otherAdmin.getByRole("row").filter({ hasText: k2.text })).toBeVisible();

    await otherAdmin.getByRole("link", { name: message("admin.tab.deleted") }).click();

    await expect(otherAdmin.getByRole("row").filter({ hasText: k1.text })).toContainText(
      message("admin.audit.deleted").replace("{login}", adminA.username),
    );
  });

  await test.step("3. Гость перезагружает ленту → К1 нет", async () => {
    await guest.reload();

    await expect(feed.getByText(k2.text)).toBeVisible();
    await expect(feed.getByText(k1.text)).toHaveCount(0);
  });
});
