// @ts-check
import { describe, expect, it } from "vitest";

import { noRealTime } from "../rules/no-real-time.js";
import { TEST_FILES } from "../test-locations.js";

const PHP = "common/tests/unit/ATest.php";
const JS = "backend/web/js/__tests__/a.test.js";

describe("no-real-time (ДТ-1)", () => {
  it.each([
    [PHP, "<?php\n$a = time();\n", "time("],
    [PHP, "<?php\n$a = date('Y');\n", "date("],
    [PHP, "<?php\n$a = microtime(true);\n", "microtime("],
    [PHP, "<?php\n$a = hrtime();\n", "hrtime("],
    [PHP, "<?php\n$a = strtotime('+1 day');\n", "strtotime("],
    [PHP, "<?php\n$a = \\time();\n", "time("],
    [PHP, "<?php\n$a = new \\DateTimeImmutable();\n", String.raw`new \DateTimeImmutable()`],
    [PHP, "<?php\n$a = new DateTime();\n", "new DateTime()"],
    [PHP, "<?php\n$a = new DateTimeImmutable('now');\n", "new DateTimeImmutable('now')"],
    [JS, "\nconst a = Date.now();\n", "Date.now("],
    [JS, "\nconst a = new Date();\n", "new Date()"],
    [JS, "\nconst a = performance.now();\n", "performance.now("],
  ])("%s: «%s» → нарушение на строке 2", (path, text, found) => {
    expect(noRealTime.check({ path, text })).toStrictEqual([
      {
        line: 2,
        message: `«${found}» — реальное время в тесте: используйте ClockInterface/MockClock или vi.useFakeTimers()`,
      },
    ]);
  });

  it.each([
    ["дата с аргументом", PHP, "<?php\n$a = new DateTimeImmutable('2026-11-24T11:05:00Z');\n"],
    ["метод с тем же именем", PHP, "<?php\n$a = $clock->time();\n$b = Carbon::date();\n"],
    ["функция в строке", PHP, "<?php\n$a = 'time()';\n"],
    ["Date с аргументом", JS, "\nconst a = new Date('2026-11-24T11:05:00Z');\n"],
    ["идентификатор с date", JS, "\nconst a = formatDate(iso);\n"],
    ["конструктор внутри строки", JS, "\nconst sample = 'new Date()';\n"],
  ])("%s → нарушений нет", (_case, path, text) => {
    expect(noRealTime.check({ path, text })).toStrictEqual([]);
  });

  it("несколько нарушений в файле идут по порядку строк", () => {
    const text = "\nconst a = Date.now();\nconst b = new Date();\nconst c = performance.now();\n";

    expect(noRealTime.check({ path: JS, text }).map(({ line }) => line)).toStrictEqual([2, 3, 4]);
  });

  it("применяется ко всем тестам проекта", () => {
    expect(noRealTime.files).toStrictEqual(TEST_FILES);
  });
});
