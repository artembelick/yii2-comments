import { requireEnv } from "../../../support/env.ts";
import { admin, comment, uniqueTag } from "../../../support/factories.ts";
import { message } from "../../../support/messages.ts";
import { expect, test } from "../../../support/test.ts";

test("E2E-01: путь комментария от формы до админки", async ({ page, guest, db, faker }) => {
  const adminA = await admin(db, faker);
  const k0 = await comment(db, faker);
  const k1 = { name: faker.person.firstName(), text: `${faker.lorem.sentence()} ${uniqueTag(faker)}` };
  const adminList = page.getByRole("table");
  const feed = guest.getByRole("region", { name: message("feed.title") });

  await test.step("1. Админ А открывает админку без входа → страница логина (P1)", async () => {
    await page.goto(requireEnv("ADMIN_URL"));

    await expect(page).toHaveURL(`${requireEnv("ADMIN_URL")}/login`);
    await expect(page.getByRole("heading", { name: message("login.title") })).toBeVisible();
  });

  await test.step("2. А входит → список «Активные», в нём К0 (P2)", async () => {
    await page.getByLabel(message("login.username")).fill(adminA.username);
    await page.getByLabel(message("login.password")).fill(adminA.password);
    await page.getByRole("button", { name: message("login.submit") }).click();

    await expect(page.getByRole("heading", { name: message("admin.title") })).toBeVisible();
    await expect(page.getByRole("link", { name: message("admin.tab.active") })).toHaveAttribute("aria-current", "page");
    await expect(adminList.getByRole("row").filter({ hasText: k0.text })).toBeVisible();
  });

  await test.step("3. Гость в своём контексте открывает публичную часть, видит форму и К0 (P4)", async () => {
    await guest.goto("/");

    await expect(guest.getByRole("textbox", { name: message("form.name") })).toBeVisible();
    await expect(feed.getByText(k0.text)).toBeVisible();
  });

  await test.step("4. Гость отправляет К1 → флэш, К1 первым в ленте над К0 (P5–P7)", async () => {
    await guest.getByRole("textbox", { name: message("form.name") }).fill(k1.name);
    await guest.getByRole("textbox", { name: message("form.text") }).fill(k1.text);
    await guest.getByRole("button", { name: message("form.submit") }).click();

    await expect(guest.getByText(message("comment.created"))).toBeVisible();
    await expect(feed.getByRole("listitem").nth(0)).toContainText(k1.text);
    await expect(feed.getByRole("listitem").nth(1)).toContainText(k0.text);
  });

  await test.step("5. У А индикатор «онлайн» (P3)", async () => {
    await expect(page.getByRole("status", { name: message("ws.status.label") })).toHaveText(
      message("ws.status.online"),
    );
  });

  await test.step("6. У А строка К1 появилась первой без перезагрузки и подсвечена (P8–P10)", async () => {
    const firstRow = adminList.getByRole("row").nth(1);

    await expect(firstRow).toContainText(k1.text);
    await expect(firstRow).toHaveAttribute("data-highlighted", "true");
  });
});
