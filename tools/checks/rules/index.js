// @ts-check
import { e2eAccessibleLocators } from "./e2e-accessible-locators.js";
import { entrypointLimits } from "./entrypoint-limits.js";
import { noFixedWaits } from "./no-fixed-waits.js";
import { noInlineDisable } from "./no-inline-disable.js";
import { noLogicInTests } from "./no-logic-in-tests.js";
import { noRealTime } from "./no-real-time.js";
import { noSkipOnly } from "./no-skip-only.js";
import { phpMaxParams } from "./php-max-params.js";
import { strictAssertions } from "./strict-assertions.js";
import { testIds } from "./test-ids.js";

/** @typedef {import("../source.js").SourceFile} SourceFile */
/** @typedef {import("../source.js").Violation} Violation */
/** @typedef {import("./test-ids.js").PlanContext} PlanContext */
/**
 * @typedef {object} Rule
 * @property {string} id
 * @property {readonly string[]} files
 * @property {readonly string[]} exclude
 * @property {(file: SourceFile, context: PlanContext) => Violation[]} check
 */

/** @type {readonly Rule[]} */
export const RULES = [
  noInlineDisable,
  noSkipOnly,
  entrypointLimits,
  testIds,
  noRealTime,
  noFixedWaits,
  noLogicInTests,
  e2eAccessibleLocators,
  strictAssertions,
  phpMaxParams,
];
