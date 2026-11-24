// @ts-check
import { describe, expect, it } from "vitest";

import { phpTestTitles } from "../test-names.js";

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
