import { listRow, openAdminPage } from "../../../support/admin.ts";
import { admin, comment } from "../../../support/factories.ts";
import { message } from "../../../support/messages.ts";
import { expect, test } from "../../../support/test.ts";

test("E2E-03: удаление видно второму админу и гостю", async ({ page: admin1Page, admin2Page, guest, db, faker }) => {
  const admin1 = await admin(db, faker);
  const admin2 = await admin(db, faker);
  // Соседний остаётся: по нему видно, что список на месте, а не пуст (ПР-6).
  const neighbourComment = await comment(db, faker);
  const deletedComment = await comment(db, faker);
  const feed = guest.getByRole("region", { name: message("feed.title") });
  await guest.goto("/");
  await expect(feed.getByText(deletedComment.text)).toBeVisible();
  await openAdminPage(admin1Page, admin1);
  await openAdminPage(admin2Page, admin2);

  await test.step("1. Админ 1 удаляет комментарий и подтверждает → он пропал из «Активных» у Админа 1", async () => {
    const dialogs: string[] = [];
    admin1Page.once("dialog", (dialog) => {
      dialogs.push(dialog.message());
      void dialog.accept();
    });

    await listRow(admin1Page, deletedComment.text)
      .getByRole("button", { name: message("admin.action.delete") })
      .click();

    await expect(admin1Page.getByText(message("admin.deleted"))).toBeVisible();
    expect(dialogs).toStrictEqual([message("admin.confirm.delete")]);
    await expect(listRow(admin1Page, neighbourComment.text)).toBeVisible();
    await expect(listRow(admin1Page, deletedComment.text)).toHaveCount(0);
  });

  await test.step("2. У Админа 2 комментарий вживую ушёл из «Активных», на «Удалённых» он с «удалил: <логин Админа 1>»", async () => {
    await expect(listRow(admin2Page, deletedComment.text)).toHaveCount(0);
    await expect(listRow(admin2Page, neighbourComment.text)).toBeVisible();

    await admin2Page.getByRole("link", { name: message("admin.tab.deleted") }).click();

    await expect(listRow(admin2Page, deletedComment.text)).toContainText(
      message("admin.audit.deleted").replace("{login}", admin1.username),
    );
  });

  await test.step("3. Гость перезагружает ленту → удалённого комментария нет, соседний на месте", async () => {
    await guest.reload();

    await expect(feed.getByText(neighbourComment.text)).toBeVisible();
    await expect(feed.getByText(deletedComment.text)).toHaveCount(0);
  });
});
