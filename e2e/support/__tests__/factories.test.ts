import { describe, expect, it, vi } from "vitest";

import { uniqueTag } from "../factories.ts";
import { seededFaker } from "../faker.ts";

describe("метка тестовых данных", () => {
  it("UNIT-E2E-TAG-01: метка — TEST_RUN_ID из окружения и 4 символа [a-z0-9] из Faker", () => {
    vi.stubEnv("TEST_RUN_ID", "k3x9");

    const tag = uniqueTag(seededFaker(42));

    expect(tag).toMatch(/^k3x9[a-z0-9]{4}$/u);
  });

  it("UNIT-E2E-TAG-01: при одном seed и разных TEST_RUN_ID метки разные", () => {
    const tags = [uniqueTag(seededFaker(42), "k3x9"), uniqueTag(seededFaker(42), "p7q2")];

    expect(tags[0]).not.toBe(tags[1]);
  });

  it("UNIT-E2E-TAG-01: при одном seed и одном TEST_RUN_ID метки одинаковые", () => {
    const tags = [uniqueTag(seededFaker(42), "k3x9"), uniqueTag(seededFaker(42), "k3x9")];

    expect(tags[0]).toBe(tags[1]);
  });

  it.each([
    { name: "не задан", runId: undefined },
    { name: "пустой", runId: "" },
    { name: "3 символа", runId: "k3x" },
    { name: "5 символов", runId: "k3x9a" },
    { name: "заглавные буквы", runId: "K3X9" },
    { name: "дефис", runId: "k3-9" },
  ])("UNIT-E2E-TAG-02: TEST_RUN_ID $name → ошибка с именем переменной", ({ runId }) => {
    vi.stubEnv("TEST_RUN_ID", runId);
    const faker = seededFaker(42);

    expect(() => uniqueTag(faker)).toThrow(/TEST_RUN_ID/u);
  });
});
