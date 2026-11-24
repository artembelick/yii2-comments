import { message } from "../../support/messages.ts";
import { expect, test } from "../../support/test.ts";

test("SMOKE-01: публичная часть открывается с формой и лентой", async ({ page }) => {
  const response = await page.goto("/");

  expect(response?.status()).toBe(200);
  await expect(page.getByRole("heading", { name: message("feed.title"), level: 1 })).toBeVisible();
  await expect(page.getByRole("textbox", { name: message("form.name") })).toBeVisible();
  await expect(page.getByRole("textbox", { name: message("form.text") })).toBeVisible();
  await expect(page.getByRole("button", { name: message("form.submit") })).toBeVisible();
  await expect(page.getByRole("region", { name: message("feed.title") })).toBeVisible();
});
