
test.only("SMOKE-01: публичная часть открывается", async ({ page }) => {
  await expect(page.getByRole("heading")).toBeVisible();
});
