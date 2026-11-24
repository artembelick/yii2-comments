import { Faker, en, ru } from "@faker-js/faker";

/** Параметры 32-битного FNV-1a. */
const FNV_OFFSET_BASIS = 2_166_136_261;
const FNV_PRIME = 16_777_619;

/**
 * Seed из имени теста (ДТ-2): повторный прогон того же теста даёт те же данные.
 * Хэш FNV-1a по байтам UTF-8 от полного имени, а не только от ID: у тестов с общим ID и у наборов
 * провайдера (REG-WS-07 — создание, правка, удаление, восстановление) иначе совпали бы метки и логины.
 * Так же поступает PHP-версия (common/tests/_support/TestSeed.php).
 */
export function seedFromTitle(title: string): number {
  return new TextEncoder()
    .encode(title)
    .reduce((hash, byte) => Math.imul(hash ^ byte, FNV_PRIME) >>> 0, FNV_OFFSET_BASIS);
}

export function seededFaker(seed: number): Faker {
  const faker = new Faker({ locale: [ru, en] });
  faker.seed(seed);

  return faker;
}
