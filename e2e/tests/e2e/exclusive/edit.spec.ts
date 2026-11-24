import { openAdminPage } from "../../../support/admin.ts";
import { admin, comment, uniqueTag } from "../../../support/factories.ts";
import { message } from "../../../support/messages.ts";
import { expect, test } from "../../../support/test.ts";

test("E2E-02: правка видна второму админу и гостю", async ({ page, otherAdmin, guest, db, faker }) => {
  const adminA = await admin(db, faker);
  const adminB = await admin(db, faker);
  const k1 = await comment(db, faker);
  const newText = `${faker.lorem.sentence()} ${uniqueTag(faker)}`;
  const updatedByA = message("admin.audit.updated").replace("{login}", adminA.username);
  await openAdminPage(page, adminA);
  await openAdminPage(otherAdmin, adminB);

  await test.step("1. А правит текст К1 → флэш, в списке у А новый текст и «изменил: А»", async () => {
    await page
      .getByRole("row")
      .filter({ hasText: k1.author })
      .getByRole("link", { name: message("admin.action.edit") })
      .click();
    await page.getByLabel(message("admin.edit.text")).fill(newText);
    await page.getByRole("button", { name: message("admin.edit.save") }).click();

    await expect(page.getByText(message("admin.updated"))).toBeVisible();
    await expect(page.getByRole("row").filter({ hasText: k1.author })).toContainText(newText);
    await expect(page.getByRole("row").filter({ hasText: k1.author })).toContainText(updatedByA);
  });

  await test.step("2. У Б строка К1 вживую: новый текст и «изменил: А»", async () => {
    const rowAtB = otherAdmin.getByRole("row").filter({ hasText: k1.author });

    await expect(rowAtB).toContainText(newText);
    await expect(rowAtB).toContainText(updatedByA);
  });

  await test.step("3. Гость перезагружает ленту → новый текст без пометок о правке", async () => {
    const feed = guest.getByRole("region", { name: message("feed.title") });

    await guest.goto("/");

    await expect(feed.getByRole("listitem").filter({ hasText: k1.author })).toContainText(newText);
    await expect(feed).not.toContainText(updatedByA);
  });
});
