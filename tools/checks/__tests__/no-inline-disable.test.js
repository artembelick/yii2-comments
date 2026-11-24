// @ts-check
import { describe, expect, it } from "vitest";

import { noInlineDisable } from "../rules/no-inline-disable.js";

describe("no-inline-disable", () => {
  it.each([
    ["src/a.php", "<?php\n$a = 1; // @phpstan-ignore argument.type\n", "@phpstan-ignore"],
    ["src/a.php", "<?php\n/** @codeCoverageIgnore */\nfunction a() {}\n", "@codeCoverageIgnore"],
    ["src/a.php", "<?php\n# phpcs:ignore Generic.Foo\n", "phpcs:ignore"],
    ["src/a.php", "<?php\n// phpcs:disable\n", "phpcs:disable"],
    ["src/a.php", "<?php\n/** @noRector */\n", "@noRector"],
    ["src/a.php", "<?php\n/** @psalm-suppress Foo */\n", "@psalm-suppress"],
    ["src/a.js", "\n// oxlint-disable-next-line no-console\n", "oxlint-disable"],
    ["src/a.js", "\n/* eslint-disable */\n", "eslint-disable"],
    ["src/a.ts", "\n// @ts-ignore\n", "@ts-ignore"],
    ["src/a.ts", "\n// @ts-expect-error\n", "@ts-expect-error"],
    ["src/a.js", "\n// @ts-nocheck\n", "@ts-nocheck"],
    ["src/a.js", "\n/* v8 ignore next */\n", "v8 ignore"],
    ["src/a.js", "\n/* istanbul ignore next */\n", "istanbul ignore"],
    ["src/a.js", "\n/* c8 ignore next */\n", "c8 ignore"],
    ["src/a.js", "\n// prettier-ignore\n", "prettier-ignore"],
    ["src/a.js", "\n// oxfmt-ignore\n", "oxfmt-ignore"],
  ])("%s с «%s» в комментарии → нарушение на строке 2", (path, text, marker) => {
    const violations = noInlineDisable.check({ path, text });

    expect(violations).toStrictEqual([
      {
        line: 2,
        message: `inline-отключение «${marker}» запрещено: отключайте правило в конфиге с комментарием «почему»`,
      },
    ]);
  });

  it.each([
    ["строка PHP", "src/a.php", "<?php\n$doc = '@phpstan-ignore';\n"],
    ["строка JS", "src/a.js", '\nconst doc = "// eslint-disable";\n'],
    ["шаблонная строка JS", "src/a.js", "\nconst doc = `/* v8 ignore next */`;\n"],
    ["обычный комментарий", "src/a.php", "<?php\n// Почему так: см. ADR 0002\n"],
  ])("%s без отключений → нарушений нет", (_case, path, text) => {
    expect(noInlineDisable.check({ path, text })).toStrictEqual([]);
  });

  it("применяется к PHP, JS и TS по всему проекту, кроме самого правила и деревьев-фикстур", () => {
    expect({ files: noInlineDisable.files, exclude: noInlineDisable.exclude }).toStrictEqual({
      files: ["**/*.{php,js,mjs,cjs,ts}"],
      exclude: ["tools/checks/rules/no-inline-disable.js", "tools/checks/__tests__/fixtures/**"],
    });
  });
});
