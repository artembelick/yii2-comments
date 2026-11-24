import { describe, expect, it, vi } from "vitest";

import { seededFaker } from "../faker.ts";
import { testRunId, uniqueTag } from "../tag.ts";

describe("метка тестовых данных", () => {
  it("UNIT-E2E-TAG-01: метка — TEST_RUN_ID из окружения и 4 символа [a-z0-9] из Faker", () => {
    vi.stubEnv("TEST_RUN_ID", "k3x9");

    const tag = uniqueTag(seededFaker(42));

    expect(tag).toMatch(/^k3x9[a-z0-9]{4}$/u);
  });

  it("UNIT-E2E-TAG-01: при одном seed и разных TEST_RUN_ID метки разные", () => {
    const firstRunTag = uniqueTag(seededFaker(42), "k3x9");
    const secondRunTag = uniqueTag(seededFaker(42), "p7q2");

    expect(firstRunTag).not.toBe(secondRunTag);
  });

  it("UNIT-E2E-TAG-01: при одном seed и одном TEST_RUN_ID метки одинаковые", () => {
    const firstTag = uniqueTag(seededFaker(42), "k3x9");
    const sameRunTag = uniqueTag(seededFaker(42), "k3x9");

    expect(firstTag).toBe(sameRunTag);
  });

  const invalidRunIds = [
    { name: "не задан", runId: undefined },
    { name: "пустой", runId: "" },
    { name: "3 символа", runId: "k3x" },
    { name: "5 символов", runId: "k3x9a" },
    { name: "заглавные буквы", runId: "K3X9" },
    { name: "дефис", runId: "k3-9" },
  ];

  it.each(invalidRunIds)(
    "UNIT-E2E-TAG-02: TEST_RUN_ID $name → uniqueTag бросает ошибку с именем переменной",
    ({ runId }) => {
      vi.stubEnv("TEST_RUN_ID", runId);
      const faker = seededFaker(42);

      expect(() => uniqueTag(faker)).toThrow(/TEST_RUN_ID/u);
    },
  );

  it.each(invalidRunIds)(
    "UNIT-E2E-TAG-02: TEST_RUN_ID $name → testRunId бросает ошибку с именем переменной",
    ({ runId }) => {
      vi.stubEnv("TEST_RUN_ID", runId);

      expect(() => testRunId()).toThrow(/TEST_RUN_ID/u);
    },
  );
});
