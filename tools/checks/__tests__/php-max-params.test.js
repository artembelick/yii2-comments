// @ts-check
import { describe, expect, it } from "vitest";

import { phpMaxParams } from "../rules/php-max-params.js";

const PHP = "common/services/CommentService.php";

describe("php-max-params", () => {
  it.each([
    ["метод", "<?php\npublic function a($a, $b, $c, $d, $e, $f): void {}\n"],
    ["функция", "<?php\nfunction a(int $a, int $b, int $c, int $d, int $e, int $f) {}\n"],
    ["замыкание", "<?php\n$a = function ($a, $b, $c, $d, $e, $f) {};\n"],
    ["стрелочная функция", "<?php\n$a = fn($a, $b, $c, $d, $e, $f) => 1;\n"],
    [
      "promoted-свойства в конструкторе",
      "<?php\npublic function __construct(\n    private A $a,\n    private B $b,\n    private C $c,\n    private D $d,\n    private E $e,\n    private F $f,\n) {}\n",
    ],
  ])("%s с 6 параметрами → нарушение на строке объявления", (_case, text) => {
    expect(phpMaxParams.check({ path: PHP, text })).toStrictEqual([{ line: 2, message: "6 параметров, лимит 5" }]);
  });

  it.each([
    ["5 параметров", "<?php\npublic function a($a, $b, $c, $d, $e): void {}\n"],
    ["без параметров", "<?php\npublic function a(): void {}\n"],
    [
      "значения по умолчанию с запятыми",
      "<?php\npublic function a(array $a = [1, 2, 3, 4], string $b = 'x, y, z', ?C $c = null): void {}\n",
    ],
    ["атрибут с аргументами", "<?php\npublic function a(#[Foo(1, 2, 3, 4, 5)] int $a): void {}\n"],
    ["вызов с 6 аргументами", "<?php\n$a->call(1, 2, 3, 4, 5, 6);\n"],
  ])("%s → нарушений нет", (_case, text) => {
    expect(phpMaxParams.check({ path: PHP, text })).toStrictEqual([]);
  });

  it("применяется к PHP-коду приложений", () => {
    expect(phpMaxParams.files).toStrictEqual(["{common,frontend,backend,console,ws}/**/*.php"]);
  });
});
