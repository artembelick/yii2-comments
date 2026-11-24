import type { Faker } from "@faker-js/faker";
import { Pool } from "pg";

import { requireEnv } from "./env.ts";

/**
 * Единственное место, где /e2e знает схему БД. Фабрики пишут предусловия прямо в Postgres стенда,
 * имена и поля у них те же, что у PHP-фикстур (admin, comment). Сами фабрики появляются вместе
 * с таблицами в срезе 02 (E2E-FIX-01 сверяет их со схемой).
 */
export function connectDb(): Pool {
  return new Pool({
    host: requireEnv("POSTGRES_HOST"),
    port: Number(requireEnv("POSTGRES_PORT")),
    database: requireEnv("POSTGRES_DB"),
    user: requireEnv("POSTGRES_USER"),
    password: requireEnv("POSTGRES_PASSWORD"),
    max: 2,
  });
}

/** Уникальная метка теста: по ней тест находит свои строки и проверяет только их (ИЗ-3). */
export function uniqueTag(faker: Faker): string {
  return faker.string.alphanumeric({ length: 4, casing: "lower" });
}
