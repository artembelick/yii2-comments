// @ts-check
import { describe, expect, it } from "vitest";

import { jsCoverage, phpCoverage } from "../coverage.js";

const CLOVER = `<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1">
  <project timestamp="1">
    <file name="/app/ws/OriginPolicy.php">
      <metrics loc="40" methods="2" coveredmethods="1" statements="7" coveredstatements="5"/>
    </file>
    <metrics files="57" loc="3179" methods="180" coveredmethods="179" statements="625" coveredstatements="620"/>
  </project>
</coverage>
`;

const SUMMARY = JSON.stringify({
  total: {
    lines: { total: 461, covered: 460, skipped: 0, pct: 99.78 },
    branches: { total: 177, covered: 177, skipped: 0, pct: 100 },
  },
  "/app/backend/web/js/api.js": { lines: { total: 26, covered: 26, skipped: 0, pct: 100 } },
});

describe("покрытие для страницы отчёта", () => {
  it("строки PHP: строки проекта из итоговых метрик clover, а не из файла", () => {
    expect(phpCoverage(CLOVER)).toStrictEqual({ lines: { covered: 620, total: 625 } });
  });

  it("строки и ветки JS из итога json-summary", () => {
    expect(jsCoverage(SUMMARY)).toStrictEqual({
      lines: { covered: 460, total: 461 },
      branches: { covered: 177, total: 177 },
    });
  });

  it.each([
    ["PHP: отчёта нет (unit не запускался)", () => phpCoverage()],
    ["PHP: не clover", () => phpCoverage("<testsuites/>")],
    ["JS: отчёта нет", () => jsCoverage()],
    ["JS: не json-summary", () => jsCoverage("{}")],
  ])("%s → нет итога: на странице «нет данных», а не выдуманная цифра", (_case, read) => {
    expect(read()).toBeUndefined();
  });
});
