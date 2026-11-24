// @ts-check
import { describe, expect, it } from "vitest";

import messages from "../../../../tests/_data/messages.json" with { type: "json" };
import { formatDate, formatNewBanner } from "../format.js";

describe("format", () => {
  it("UNIT-JS-FMT-01: 2026-11-24T11:05:00Z в Europe/Moscow → 24.11.2026 14:05, как у PHP", () => {
    const formatted = formatDate("2026-11-24T11:05:00Z", "Europe/Moscow");

    expect(formatted).toBe("24.11.2026 14:05");
  });

  it.each([
    [1, "1 новый комментарий"],
    [2, "2 новых комментария"],
    [5, "5 новых комментариев"],
    [11, "11 новых комментариев"],
    [21, "21 новый комментарий"],
    [22, "22 новых комментария"],
    [25, "25 новых комментариев"],
  ])("UNIT-JS-FMT-02: %i → «%s»", (count, expected) => {
    const banner = formatNewBanner(count, messages["admin.banner.new"]);

    expect(banner).toBe(expected);
  });
});
