// @ts-check
import { describe, expect, it } from "vitest";

import { pathMatrix } from "../path-matrix.js";

const PLAN_IDS = [
  "UNIT-LOGIN-01",
  "UNIT-LOGIN-02",
  "UNIT-MH-01",
  "UNIT-MH-02",
  "UNIT-MH-03",
  "UNIT-JS-WS-01",
  "UNIT-JS-WS-02",
  "SMOKE-02",
  "E2E-01",
  "REG-A-01",
  "REG-A-02",
  "REG-A-02a",
  "REG-A-03",
  "REG-WS-01",
];

const README = `## Путь комментария

| Шаг | Что происходит | smoke | e2e | regression | unit |
|---|---|---|---|---|---|
| P1 | Админ входит, \`make admin\` | SMOKE-02 | E2E-01 | REG-A-01…A-02a, WS-01 | LOGIN, MH-01…MH-02, JS-WS-01 |
| P2 | Список | | E2E-01 | | |
| P3 | Без колонок тестов |

Вне пути комментария:
- logout: E2E-01;
`;

describe("матрица пути комментария", () => {
  it("строки P1…: недостающие ячейки пусты, короткие ID получают префикс колонки, диапазоны и семейства раскрываются по порядку плана", () => {
    expect(pathMatrix(README, PLAN_IDS)).toStrictEqual([
      {
        step: "P1",
        what: "Админ входит, `make admin`",
        cells: [
          [{ label: "SMOKE-02", ids: ["SMOKE-02"] }],
          [{ label: "E2E-01", ids: ["E2E-01"] }],
          [
            { label: "REG-A-01…A-02a", ids: ["REG-A-01", "REG-A-02", "REG-A-02a"] },
            { label: "REG-WS-01", ids: ["REG-WS-01"] },
          ],
          [
            { label: "UNIT-LOGIN", ids: ["UNIT-LOGIN-01", "UNIT-LOGIN-02"] },
            { label: "UNIT-MH-01…MH-02", ids: ["UNIT-MH-01", "UNIT-MH-02"] },
            { label: "UNIT-JS-WS-01", ids: ["UNIT-JS-WS-01"] },
          ],
        ],
      },
      { step: "P2", what: "Список", cells: [[], [{ label: "E2E-01", ids: ["E2E-01"] }], [], []] },
      { step: "P3", what: "Без колонок тестов", cells: [[], [], [], []] },
    ]);
  });

  it.each([
    ["ID нет в плане", "REG-A-09", "REG-A-09 из матрицы пути нет в docs/test-plans/"],
    ["конец диапазона нет в плане", "REG-A-01…A-09", "REG-A-01…A-09 из матрицы пути нет в docs/test-plans/"],
    ["диапазон задом наперёд", "REG-A-03…A-01", "REG-A-03…A-01 из матрицы пути нет в docs/test-plans/"],
  ])("%s → ошибка: матрица не должна ссылаться на то, чего нет", (_case, token, message) => {
    const readme = `| P1 | Вход | | | ${token} | |\n`;

    expect(() => pathMatrix(readme, PLAN_IDS)).toThrow(message);
  });
});
