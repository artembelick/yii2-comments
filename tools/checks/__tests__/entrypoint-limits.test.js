// @ts-check
import { describe, expect, it } from "vitest";

import { entrypointLimits } from "../rules/entrypoint-limits.js";

const PHP = "ws/runtime/server.php";
const JS = "backend/web/js/main.js";

describe("entrypoint-limits", () => {
  it.each([
    ["if", PHP, "<?php\nif ($a) {}\n", "if"],
    ["elseif", PHP, "<?php\n} elseif ($a) {\n", "elseif"],
    ["else", JS, "\n} else {\n", "else"],
    ["match", PHP, "<?php\n$a = match ($b) {};\n", "match"],
    ["switch", JS, "\nswitch (a) {}\n", "switch"],
    ["for", JS, "\nfor (const a of b) {}\n", "for"],
    ["foreach", PHP, "<?php\nforeach ($a as $b) {}\n", "foreach"],
    ["while", PHP, "<?php\nwhile (true) {}\n", "while"],
    ["do", JS, "\ndo {\n", "do"],
    ["тернарный оператор", JS, "\nconst a = b ? c : d;\n", "?"],
    ["короткий тернарный", PHP, "<?php\n$a = $b ?: $c;\n", "?:"],
  ])("%s → нарушение на строке 2", (_case, path, text, construct) => {
    expect(entrypointLimits.check({ path, text })).toStrictEqual([
      { line: 2, message: `«${construct}» запрещён в entrypoint: логика уходит в классы и модули под unit-тестами` },
    ]);
  });

  it.each([
    ["nullable-тип", PHP, "<?php\nfunction a(?int $b): ?string {}\n"],
    ["nullsafe-вызов", PHP, "<?php\n$a?->b();\n"],
    ["оператор ??", PHP, "<?php\n$a = $b ?? $c;\n"],
    ["if в строке", JS, "\nconst a = 'if (b) {}';\n"],
    ["for в комментарии", PHP, "<?php\n// for each connection\n"],
    ["слово в идентификаторе", JS, "\nconst format = doSomething(forEachRow);\n"],
  ])("%s → нарушений нет", (_case, path, text) => {
    expect(entrypointLimits.check({ path, text })).toStrictEqual([]);
  });

  it("61 строка → нарушение лимита длины", () => {
    const text = "<?php\n".padEnd(66, "\n");

    expect(entrypointLimits.check({ path: PHP, text })).toStrictEqual([
      { line: 61, message: "в entrypoint 61 строк, лимит 60" },
    ]);
  });

  it("60 строк → нарушений нет", () => {
    const text = "<?php\n".padEnd(65, "\n");

    expect(entrypointLimits.check({ path: PHP, text })).toStrictEqual([]);
  });

  it("применяется ко всем entrypoint'ам, исключённым из покрытия: ws/runtime, main.js и bin.js проверок", () => {
    expect(entrypointLimits.files).toStrictEqual([
      "ws/runtime/**/*.php",
      "backend/web/js/main.js",
      "tools/checks/bin.js",
    ]);
  });
});
