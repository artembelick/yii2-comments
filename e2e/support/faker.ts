import { Faker, en, ru } from "@faker-js/faker";

/** Параметры 32-битного FNV-1a. */
const FNV_OFFSET_BASIS = 2_166_136_261;
const FNV_PRIME = 16_777_619;
const TEST_ID = /^(?:UNIT|REG|SMOKE|E2E)(?:-[A-Z0-9]+)+[a-z]?/u;

/**
 * Seed из ID теста (ДТ-2): повторный прогон того же теста даёт те же данные.
 * Хэш FNV-1a по байтам UTF-8 от ID, а если ID нет — от полного имени теста.
 */
export function seedFromTitle(title: string): number {
  const source = TEST_ID.exec(title)?.[0] ?? title;

  return new TextEncoder()
    .encode(source)
    .reduce((hash, byte) => Math.imul(hash ^ byte, FNV_PRIME) >>> 0, FNV_OFFSET_BASIS);
}

export function seededFaker(seed: number): Faker {
  const faker = new Faker({ locale: [ru, en] });
  faker.seed(seed);

  return faker;
}
