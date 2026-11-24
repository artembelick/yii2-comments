// @ts-check
import { describe, expect, it } from "vitest";

import { parseResult, phpTestTitles, prepareResults, summarizeRun } from "../results.js";

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
  historyId: "h",
  parameters: [],
  labels: [],
  ...fields,
});

const PHP_UNIT = {
  path: "common/tests/unit/web/ErrorPageTest.php",
  text: `<?php
namespace common\\tests\\unit\\web;

final class ErrorPageTest extends Unit
{
    #[TestDox('UNIT-ERR-01: заголовок по коду')]
    #[DataProvider('codes')]
    public function testTitle(int $code): void
    {
    }

    public static function codes(): array
    {
    }
}
`,
};

const PHP_CEST = {
  path: "frontend/tests/regression/FeedCest.php",
  text: `<?php
namespace frontend\\tests\\regression;

final class FeedCest
{
    /** REG-G-01: лента открывается */
    public function feedOpens(RegressionTester $I): void
    {
    }

    /**
     * REG-G-02: пустая лента
     *
     * Ярус pristine.
     */
    #[Group('pristine')]
    public function emptyFeed(RegressionTester $I): void
    {
    }

    public function _before(RegressionTester $I): void
    {
    }
}
`,
};

const PHP_GLOBAL = {
  path: "common/tests/unit/GlobalTest.php",
  text: `<?php

final class GlobalTest
{
    #[TestDox('UNIT-A-01: класс без namespace')]
    public function testA(): void
    {
    }

    public function helper(): void
    {
    }
}
`,
};

const PHP_DOCBLOCKS = {
  path: "backend/tests/regression/EventsCest.php",
  text: `<?php
namespace backend\\tests\\regression;

final class EventsCest
{
    /** Поля комментария в событии: {id, author}. */
    private const FIELDS = ['id', 'author'];

    /**
     * REG-A-70: событие опубликовано
     *
     * @param array{id: int} $example
     */
    public function eventIsPublished(RegressionTester $I): void
    {
    }

    /**
     * @param Closure(int): void $action
     */
    #[TestDox('UNIT-SVC-01: действие публикует событие')]
    #[DataProvider('actions')]
    public function testActionPublishes(Closure $action): void
    {
    }
}
`,
};

describe("имена PHP-тестов", () => {
  it("имя — из своего docblock или TestDox метода: docblock константы выше и @param не считаются", () => {
    expect(phpTestTitles([PHP_DOCBLOCKS])).toStrictEqual(
      new Map([
        [String.raw`backend\tests\regression\EventsCest::eventIsPublished`, "REG-A-70: событие опубликовано"],
        [
          String.raw`backend\tests\regression\EventsCest::testActionPublishes`,
          "UNIT-SVC-01: действие публикует событие",
        ],
      ]),
    );
  });

  it("имя теста — из TestDox или первой строки docblock, ключ — класс::метод, как fullName в Allure; методы без имени теста пропущены", () => {
    expect(phpTestTitles([PHP_UNIT, PHP_CEST, PHP_GLOBAL])).toStrictEqual(
      new Map([
        [String.raw`common\tests\unit\web\ErrorPageTest::testTitle`, "UNIT-ERR-01: заголовок по коду"],
        [String.raw`frontend\tests\regression\FeedCest::feedOpens`, "REG-G-01: лента открывается"],
        [String.raw`frontend\tests\regression\FeedCest::emptyFeed`, "REG-G-02: пустая лента"],
        [String.raw`\GlobalTest::testA`, "UNIT-A-01: класс без namespace"],
      ]),
    );
  });
});

describe("подготовка результатов к отчёту", () => {
  it.each([
    ["unit", "UNIT-JS-BO-01: первая задержка 1000", "UNIT-JS-BO-01"],
    ["regression", "REG-WS-01: токен одноразовый", "REG-WS-01"],
    ["smoke", "SMOKE-01: публичная часть открывается", "SMOKE-01"],
    ["e2e", "E2E-01: путь комментария", "E2E-01"],
  ])("набор %s по префиксу ID, внутри группа по ID; прежние группы адаптера убраны", (suite, name, id) => {
    const [prepared] = prepareResults(
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
      [result({ name: "testTitle", fullName: String.raw`common\tests\unit\web\ErrorPageTest::testTitle` })],
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

  it("тест без ID (тесты инструментов) → unit, группа инструментов, внутри — прежний набор адаптера", () => {
    const [prepared] = prepareResults(
      [result({ name: "чистое дерево → код 0", labels: [{ name: "parentSuite", value: "tools/checks CLI" }] })],
      new Map(),
    );

    expect(prepared?.labels).toStrictEqual([
      { name: "parentSuite", value: "unit" },
      { name: "suite", value: "Тесты инструментов" },
      { name: "subSuite", value: "tools/checks CLI" },
    ]);
  });

  it("наборы data provider без параметров у одного метода — разные тесты, а не повторы: номер набора в параметрах", () => {
    const sameMethod = { name: "testTitle", fullName: "A::testTitle", historyId: "h1" };

    const prepared = prepareResults([result(sameMethod), result(sameMethod), result({ historyId: "h2" })], new Map());

    expect(prepared.map(({ historyId, parameters }) => [historyId, parameters])).toStrictEqual([
      ["h1#1", [{ name: "набор данных", value: "1" }]],
      ["h1#2", [{ name: "набор данных", value: "2" }]],
      ["h2", []],
    ]);
  });
});

describe("итог прогона", () => {
  it("по наборам в порядке пирамиды: всего, прошло, упало (failed и broken), прочее; время — начало первого теста", () => {
    const results = prepareResults(
      [
        result({ name: "UNIT-A-01: a", start: Date.UTC(2026, 10, 25, 9, 30) }),
        result({ name: "UNIT-A-02: b", status: "failed", start: Date.UTC(2026, 10, 25, 9, 31) }),
        result({ name: "E2E-01: c", status: "broken", start: Date.UTC(2026, 10, 25, 9, 40) }),
        result({ name: "REG-G-01: d", status: "skipped", start: Date.UTC(2026, 10, 25, 9, 35) }),
      ],
      new Map(),
    );

    expect(summarizeRun(results)).toStrictEqual({
      startedAt: "2026-11-25T09:30:00.000Z",
      suites: [
        { suite: "unit", total: 2, passed: 1, failed: 1, other: 0 },
        { suite: "smoke", total: 0, passed: 0, failed: 0, other: 0 },
        { suite: "regression", total: 1, passed: 0, failed: 0, other: 1 },
        { suite: "e2e", total: 1, passed: 0, failed: 1, other: 0 },
      ],
    });
  });

  it("результатов нет → время неизвестно", () => {
    expect(summarizeRun([]).startedAt).toBeUndefined();
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
