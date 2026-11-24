// @ts-check
import { describe, expect, it } from "vitest";

import { parseResult, prepareResults, summarizeRun } from "../results.js";

/** @typedef {import("../results.js").AllureResult} AllureResult */

/**
 * Результат Allure в том виде, в каком его пишут адаптеры: только поля, которые читает сборка.
 *
 * @param {Partial<AllureResult>} fields
 * @returns {AllureResult}
 */
const result = (fields) => ({
  name: "",
  fullName: "",
  status: "passed",
  start: 0,
  stop: 0,
  uuid: "u",
  historyId: "h",
  parameters: [],
  labels: [],
  ...fields,
});

/**
 * @param {number} minute
 * @returns {number} 2026-11-25 09:<minute> UTC
 */
const at = (minute) => Date.UTC(2026, 10, 25, 9, minute);

describe("подготовка результатов к отчёту", () => {
  it.each([
    ["unit", "UNIT-JS-BO-01: первая задержка 1000", "UNIT-JS-BO-01"],
    ["regression", "REG-WS-01: токен одноразовый", "REG-WS-01"],
    ["smoke", "SMOKE-01: публичная часть открывается", "SMOKE-01"],
    ["e2e", "E2E-01: путь комментария", "E2E-01"],
  ])("набор %s по префиксу ID, внутри группа по ID; прежние группы адаптера убраны", (suite, name, id) => {
    const [prepared] = prepareResults(
      [
        [
          result({
            name,
            labels: [
              { name: "parentSuite", value: "e2e-parallel" },
              { name: "suite", value: "comment-path.spec.ts" },
              { name: "subSuite", value: "describe" },
              { name: "framework", value: "playwright" },
            ],
          }),
        ],
      ],
      new Map(),
    );

    expect(prepared?.labels).toStrictEqual([
      { name: "framework", value: "playwright" },
      { name: "parentSuite", value: suite },
      { name: "suite", value: id },
    ]);
  });

  it("у PHP-теста имя из исходника вместо имени метода", () => {
    const titles = new Map([
      [String.raw`common\tests\unit\web\ErrorPageTest::testTitle`, "UNIT-ERR-01: заголовок по коду"],
    ]);

    const [prepared] = prepareResults(
      [[result({ name: "testTitle", fullName: String.raw`common\tests\unit\web\ErrorPageTest::testTitle` })]],
      titles,
    );

    expect([prepared?.name, prepared?.labels]).toStrictEqual([
      "UNIT-ERR-01: заголовок по коду",
      [
        { name: "parentSuite", value: "unit" },
        { name: "suite", value: "UNIT-ERR-01" },
      ],
    ]);
  });

  it("тест своих инструментов (без ID) → отдельная группа инструментов, внутри — прежний набор адаптера", () => {
    const [prepared] = prepareResults(
      [
        [
          result({
            name: "чистое дерево → код 0",
            fullName: "comments-tools:tools/checks/__tests__/cli.test.js#tools/checks CLI чистое дерево → код 0",
            labels: [{ name: "parentSuite", value: "tools/checks CLI" }],
          }),
        ],
      ],
      new Map(),
    );

    expect(prepared?.labels).toStrictEqual([
      { name: "parentSuite", value: "Тесты инструментов" },
      { name: "suite", value: "tools/checks CLI" },
    ]);
  });

  it("тест продукта без ID в имени → ошибка: тест не должен молча уйти в чужую группу", () => {
    expect(() =>
      prepareResults(
        [[result({ name: "feedOpens", fullName: String.raw`frontend\tests\regression\FeedCest::feedOpens` })]],
        new Map(),
      ),
    ).toThrow(String.raw`frontend\tests\regression\FeedCest::feedOpens: у теста продукта нет ID в начале имени`);
  });

  it("наборы data provider без параметров у одного метода — разные тесты, а не повторы: номер набора внутри прогона", () => {
    const sameMethod = { name: "UNIT-A-01: a", fullName: "A::testTitle", historyId: "h1" };

    const prepared = prepareResults(
      [[result(sameMethod), result(sameMethod), result({ name: "UNIT-A-02: b", historyId: "h2" })]],
      new Map(),
    );

    expect(prepared.map(({ historyId, parameters }) => [historyId, parameters])).toStrictEqual([
      ["h1#1", [{ name: "набор данных", value: "1" }]],
      ["h1#2", [{ name: "набор данных", value: "2" }]],
      ["h2", []],
    ]);
  });

  it("тест, прошедший в нескольких прогонах (smoke в каждом job), — один результат; упавший важнее прошедшего", () => {
    const smoke = { name: "SMOKE-01: a", historyId: "s1:p" };

    const prepared = prepareResults(
      [
        [
          result({ ...smoke, uuid: "a" }),
          result({ name: "UNIT-A-01: a", fullName: "A::t", historyId: "h1", uuid: "d1" }),
        ],
        [
          result({ ...smoke, uuid: "b", status: "failed" }),
          result({ name: "UNIT-A-01: a", fullName: "A::t", historyId: "h1", uuid: "d2" }),
        ],
        [result({ ...smoke, uuid: "c" })],
      ],
      new Map(),
    );

    expect(prepared.map(({ uuid, historyId, status }) => [uuid, historyId, status])).toStrictEqual([
      ["b", "s1:p", "failed"],
      ["d1", "h1", "passed"],
    ]);
  });
});

describe("итог прогона", () => {
  it("по наборам в порядке пирамиды: всего, прошло, упало (failed и broken), прочее; тесты инструментов отдельно; начало и длительность прогона", () => {
    const results = prepareResults(
      [
        [
          result({ name: "UNIT-A-01: a", historyId: "1", start: at(30), stop: at(31) }),
          result({ name: "UNIT-A-02: b", historyId: "2", status: "failed", start: at(31), stop: at(32) }),
          result({ name: "E2E-01: c", historyId: "3", status: "broken", start: at(40), stop: at(42) }),
          result({ name: "REG-G-01: d", historyId: "4", status: "skipped", start: at(35), stop: at(35) }),
          result({
            name: "сборка",
            fullName: "comments-tools:tools/report/x.test.js#сборка",
            historyId: "5",
            start: at(30),
            stop: at(30),
          }),
        ],
      ],
      new Map(),
    );

    expect(summarizeRun(results)).toStrictEqual({
      startedAt: "2026-11-25T09:30:00.000Z",
      durationMs: 12 * 60_000,
      suites: [
        { suite: "unit", total: 2, passed: 1, failed: 1, other: 0 },
        { suite: "smoke", total: 0, passed: 0, failed: 0, other: 0 },
        { suite: "regression", total: 1, passed: 0, failed: 0, other: 1 },
        { suite: "e2e", total: 1, passed: 0, failed: 1, other: 0 },
      ],
      tools: { suite: "Тесты инструментов", total: 1, passed: 1, failed: 0, other: 0 },
    });
  });

  it("результатов нет → начало и длительность неизвестны", () => {
    expect(summarizeRun([])).toMatchObject({ startedAt: undefined, durationMs: undefined });
  });
});

describe("чтение результата Allure", () => {
  it("результат из JSON → объект как есть", () => {
    const json = JSON.stringify(result({ name: "UNIT-A-01: a" }));

    expect(parseResult(json, "a-result.json")).toStrictEqual(result({ name: "UNIT-A-01: a" }));
  });

  it.each([
    ["не объект", "1"],
    ["null", "null"],
    ["без меток", "[]"],
    ["метки не массив", '{"labels":{}}'],
  ])("%s → ошибка с именем файла: сломанный результат не должен молча выпасть из отчёта", (_case, json) => {
    expect(() => parseResult(json, "a-result.json")).toThrow("a-result.json: не результат Allure");
  });
});
