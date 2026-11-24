import type { Faker } from "@faker-js/faker";

/** Id прогона: одна строка на весь прогон, её генерирует Makefile (docs/test-plans/README.md, «Работа с данными»). */
const RUN_ID = /^[a-z0-9]{4}$/u;
/** Часть метки из Faker: одинакова во всех прогонах теста (ДТ-2). */
const FAKER_TAG_LENGTH = 4;

/**
 * Id прогона из окружения, проверенный. Нет или негоден — ошибка с подсказкой, а не пустая строка в метке
 * и не общая подсказка requireEnv про .env.example: TEST_RUN_ID задаёт Makefile, а не env-файл.
 */
export function testRunId(runId: string | undefined = process.env["TEST_RUN_ID"]): string {
  if (runId === undefined || !RUN_ID.test(runId)) {
    throw new Error(
      `TEST_RUN_ID должен быть из 4 символов [a-z0-9], а получено «${runId ?? ""}». Его задаёт Makefile; при запуске Playwright напрямую передайте TEST_RUN_ID в окружение.`,
    );
  }

  return runId;
}

/**
 * Уникальная метка теста: по ней тест находит свои строки и проверяет только их (ИЗ-3). Начинается с id прогона
 * TEST_RUN_ID: он отделяет её от меток того же теста в прошлых прогонах на том же стенде (ИЗ-1).
 * Правило то же, что у PHP-фикстур (FixtureFactory::tag).
 */
export function uniqueTag(faker: Faker, runId: string | undefined = process.env["TEST_RUN_ID"]): string {
  return testRunId(runId) + faker.string.alphanumeric({ length: FAKER_TAG_LENGTH, casing: "lower" });
}
