// @ts-check
import { findAll, maskSource } from "../source.js";

/** @typedef {import("../source.js").SourceFile} SourceFile */
/** @typedef {import("../source.js").Violation} Violation */

const MARKERS =
  /@phpstan-ignore|@codeCoverageIgnore|phpcs:ignore|phpcs:disable|@codingStandardsIgnore|@noRector|@psalm-suppress|oxlint-disable|eslint-disable|@ts-ignore|@ts-expect-error|@ts-nocheck|v8 ignore|istanbul ignore|c8 ignore|prettier-ignore|oxfmt-ignore/gu;

/** Правило можно отключить только в конфиге и с комментарием «почему», inline-отключения запрещены. */
export const noInlineDisable = {
  id: "no-inline-disable",
  files: ["**/*.{php,js,mjs,cjs,ts}"],
  // В самих проверках маркеры перечислены как данные.
  exclude: ["tools/checks/**"],
  /**
   * @param {SourceFile} file
   * @returns {Violation[]}
   */
  check(file) {
    const comments = maskSource(file, { comments: false, strings: true });

    return findAll(
      comments,
      MARKERS,
      ([marker]) => `inline-отключение «${marker}» запрещено: отключайте правило в конфиге с комментарием «почему»`,
    );
  },
};
