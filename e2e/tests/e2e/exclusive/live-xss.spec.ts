import { logIn } from "../../../support/admin.ts";
import { requireEnv } from "../../../support/env.ts";
import { admin, uniqueTag } from "../../../support/factories.ts";
import { message } from "../../../support/messages.ts";
import { expect, test } from "../../../support/test.ts";

test("E2E-05: разметка в комментарии, пришедшем по WS, показана текстом и не исполняется", async ({
  page,
  guest,
  db,
  faker,
}) => {
  const tag = uniqueTag(faker);
  const xssName = '<img src=x onerror="window.__xss=1">';
  const xssText = `<script>window.__xss=1</script> ${tag}`;
  await logIn(page.request, await admin(db, faker));
  await page.goto(requireEnv("ADMIN_URL"));
  await expect(page.getByRole("status", { name: message("ws.status.label") })).toHaveText(message("ws.status.online"));
  await guest.goto("/");
  await guest.getByRole("textbox", { name: message("form.name") }).fill(xssName);
  await guest.getByRole("textbox", { name: message("form.text") }).fill(xssText);

  await guest.getByRole("button", { name: message("form.submit") }).click();

  const row = page.getByRole("table").getByRole("row").filter({ hasText: tag });
  await expect(row).toContainText(xssName);
  await expect(row).toContainText(xssText);
  expect(await row.evaluate((element) => element.innerHTML)).not.toMatch(/<(?:img|script)\b/u);
  expect(await page.evaluate(() => "__xss" in globalThis)).toBe(false);
});
