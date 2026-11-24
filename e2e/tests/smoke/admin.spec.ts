import { requireEnv } from "../../support/env.ts";
import { message } from "../../support/messages.ts";
import { expect, test } from "../../support/test.ts";

const loginUrl = `${requireEnv("ADMIN_URL")}/login`;

test("SMOKE-02: страница логина админки открывается", async ({ page }) => {
  const response = await page.goto(loginUrl);

  expect(response?.status()).toBe(200);
  await expect(page.getByRole("heading", { name: message("login.title") })).toBeVisible();
  await expect(page.getByLabel(message("login.username"))).toBeVisible();
  await expect(page.getByLabel(message("login.password"))).toBeVisible();
  await expect(page.getByRole("button", { name: message("login.submit") })).toBeVisible();
});

test("SMOKE-03: админ для smoke входит и видит список «Активные»", async ({ page }) => {
  await page.goto(loginUrl);
  await page.getByLabel(message("login.username")).fill(requireEnv("SMOKE_ADMIN_LOGIN"));
  await page.getByLabel(message("login.password")).fill(requireEnv("SMOKE_ADMIN_PASSWORD"));

  await page.getByRole("button", { name: message("login.submit") }).click();

  await expect(page.getByRole("heading", { name: message("admin.title") })).toBeVisible();
  await expect(page.getByRole("link", { name: message("admin.tab.active") })).toHaveAttribute("aria-current", "page");
});
