// @ts-check
import { findAll, maskSource } from "../source.js";

/** @typedef {import("../source.js").SourceFile} SourceFile */
/** @typedef {import("../source.js").Violation} Violation */

const CSS_LOOKUPS = /\.(?:locator|\$\$?(?:eval)?|waitForSelector|querySelector(?:All)?)(?=\()/gu;

/** БР-1: элементы ищутся так, как их видит человек. CSS-классы и XPath запрещены. */
export const e2eAccessibleLocators = {
  id: "e2e-accessible-locators",
  files: ["e2e/tests/**/*.ts", "e2e/support/**/*.ts"],
  exclude: [],
  /**
   * @param {SourceFile} file
   * @returns {Violation[]}
   */
  check(file) {
    return findAll(
      maskSource(file, { comments: true, strings: true }),
      CSS_LOOKUPS,
      ([found]) =>
        `«${found.slice(1)}(» — поиск по CSS/XPath: ищите по роли, подписи или тексту (getByRole, getByLabel, getByText), data-testid — через getByTestId`,
    );
  },
};
