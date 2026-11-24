// @ts-check
import { describe, expect, it } from "vitest";

import { checkClover } from "../coverage.js";

/**
 * @param {string} files
 * @returns {string}
 */
const clover = (files) =>
  `<?xml version="1.0" encoding="UTF-8"?>\n<coverage generated="1"><project timestamp="1">${files}<metrics files="1"/></project></coverage>\n`;

const COVERED = `<file name="/app/common/web/PlaceholderController.php">
  <class name="common\\web\\PlaceholderController" namespace="global">
    <metrics complexity="1" methods="1" coveredmethods="1" statements="1" coveredstatements="1" elements="2" coveredelements="2"/>
  </class>
  <line num="16" type="method" name="actionIndex" visibility="public" complexity="1" crap="1" count="1"/>
  <metrics loc="19" ncloc="15" classes="1" methods="1" coveredmethods="1" statements="1" coveredstatements="1" elements="2" coveredelements="2"/>
</file>`;

const PARTIAL = `<file name="/app/ws/OriginPolicy.php">
  <metrics loc="40" ncloc="30" classes="1" methods="2" coveredmethods="1" statements="7" coveredstatements="5" elements="9" coveredelements="6"/>
</file>`;

describe("порог покрытия PHP по clover", () => {
  it("всё покрыто → нарушений нет", () => {
    expect(checkClover(clover(COVERED), "/app/")).toStrictEqual([]);
  });

  it("пустой отчёт без файлов → нарушений нет", () => {
    expect(checkClover(clover(""), "/app/")).toStrictEqual([]);
  });

  it("непокрытые строки или методы → нарушение с путём от корня проекта и счётчиками", () => {
    expect(checkClover(clover(COVERED + PARTIAL), "/app/")).toStrictEqual([
      "ws/OriginPolicy.php: строки 5/7, методы 1/2 — порог 100%",
    ]);
  });

  it("не clover → нарушение", () => {
    expect(checkClover("<html></html>", "/app/")).toStrictEqual(["отчёт покрытия не в формате clover"]);
  });
});
