// @ts-check
import { describe, expect, it } from "vitest";

import { createBackoff } from "../backoff.js";

describe("backoff", () => {
  it("UNIT-JS-BO-01: задержки удваиваются от 1 до 30 секунд и дальше не растут", () => {
    const backoff = createBackoff();

    const delays = Array.from({ length: 7 }, () => backoff.next());

    expect(delays).toStrictEqual([1000, 2000, 4000, 8000, 16_000, 30_000, 30_000]);
  });

  it("UNIT-JS-BO-02: после reset() снова 1 секунда", () => {
    const backoff = createBackoff();
    backoff.next();
    backoff.next();

    backoff.reset();

    expect(backoff.next()).toBe(1000);
  });
});
