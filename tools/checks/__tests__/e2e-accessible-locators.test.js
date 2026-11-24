// @ts-check
import { describe, expect, it } from "vitest";

import { e2eAccessibleLocators } from "../rules/e2e-accessible-locators.js";

const SPEC = "e2e/tests/e2e/exclusive/a.spec.ts";

describe("e2e-accessible-locators (БР-1)", () => {
  it.each([
    ["\nawait page.locator('.comment-row').click();\n", "locator("],
    ["\nawait page.$('#feed');\n", "$("],
    ["\nawait page.$$('li');\n", "$$("],
    ["\nawait page.$eval('li', (el) => el.id);\n", "$eval("],
    ["\nawait page.$$eval('li', (els) => els.length);\n", "$$eval("],
    ["\nawait page.waitForSelector('li');\n", "waitForSelector("],
    ["\nawait page.evaluate(() => document.querySelector('li'));\n", "querySelector("],
    ["\nawait page.evaluate(() => document.querySelectorAll('li'));\n", "querySelectorAll("],
  ])("«%s» → нарушение на строке 2", (text, found) => {
    expect(e2eAccessibleLocators.check({ path: SPEC, text })).toStrictEqual([
      {
        line: 2,
        message: `«${found}» — поиск по CSS/XPath: ищите по роли, подписи или тексту (getByRole, getByLabel, getByText), data-testid — через getByTestId`,
      },
    ]);
  });

  it.each([
    ["\nawait page.getByRole('button', { name: messages['admin.delete'] }).click();\n"],
    ["\nawait page.getByTestId('ws-indicator');\n"],
    ["\nconst row = page.getByRole('row').filter({ hasText: tag });\n"],
  ])("«%s» → нарушений нет", (text) => {
    expect(e2eAccessibleLocators.check({ path: SPEC, text })).toStrictEqual([]);
  });

  it("применяется к тестам и support в /e2e", () => {
    expect(e2eAccessibleLocators.files).toStrictEqual(["e2e/tests/**/*.ts", "e2e/support/**/*.ts"]);
  });
});
