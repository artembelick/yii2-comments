// @ts-check
import { describe, expect, it } from "vitest";

import { PRODUCT_TEST_FILES, TEST_FILES } from "../test-locations.js";

describe("где лежат тесты", () => {
  it("правила тестов (rules.md) действуют на тесты продукта и на тесты самих проверок", () => {
    expect(TEST_FILES).toStrictEqual([...PRODUCT_TEST_FILES, "tools/checks/__tests__/*.test.js"]);
  });
});
