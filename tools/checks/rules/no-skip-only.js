// @ts-check
import { TEST_FILES } from "../globs.js";
import { findAll, maskSource } from "../source.js";

/** @typedef {import("../source.js").SourceFile} SourceFile */
/** @typedef {import("../source.js").Violation} Violation */

const FORBIDDEN = new RegExp(
  [
    String.raw`markTest(?:Skipped|Incomplete)`,
    String.raw`\$scenario->(?:skip|incomplete)\b`,
    String.raw`@(?:skip|incomplete)\b`,
    String.raw`#\[\\?(?:Codeception\\Attribute\\)?(?:Skip|Incomplete)\b`,
    String.raw`(?://|#|\*)\s*public function (?:test\w*|\w+\(\s*\w+Tester\b)`,
    String.raw`\b(?:test|it|describe)(?:\.describe)?\.(?:skip|only|todo|fixme)\b`,
    String.raw`\b(?:xit|xtest|xdescribe|fit|fdescribe)(?=\s*\()`,
    String.raw`//\s*(?:test|it|describe)\s*\(`,
  ].join("|"),
  "gu",
);

/** Запрещены skip, only и закомментированные тесты (rules.md, раздел 7). */
export const noSkipOnly = {
  id: "no-skip-only",
  files: TEST_FILES,
  exclude: [],
  /**
   * @param {SourceFile} file
   * @returns {Violation[]}
   */
  check(file) {
    const code = maskSource(file, { comments: false, strings: true });

    return findAll(
      code,
      FORBIDDEN,
      ([found]) => `«${found.trim()}» запрещён: тест либо работает и проходит, либо удаляется`,
    );
  },
};
