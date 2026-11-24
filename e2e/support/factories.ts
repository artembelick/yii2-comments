import type { Faker } from "@faker-js/faker";
import { Pool } from "pg";

import { requireEnv } from "./env.ts";

/**
 * Единственное место, где /e2e знает схему БД. Фабрики пишут предусловия прямо в Postgres стенда,
 * имена и поля у них те же, что у PHP-фикстур (admin, comment). E2E-FIX-01 сверяет поля строк со схемой.
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

/** Пароль всех админов из фабрики. Хэш посчитан заранее с cost 4: bcrypt на каждого админа замедлил бы тесты. */
export const ADMIN_PASSWORD = "e2e-admin-password";
const ADMIN_PASSWORD_HASH = "$2y$04$Ck7VnRMpOOQC3N0OMo5Qgu3exmGsCQgVMvB7FLxVEPhmhGsbnDhAK";
const AUTH_KEY_LENGTH = 32;
/** Статусы админа, как в common\models\User. */
export const ADMIN_ACTIVE = 10;
export const ADMIN_BLOCKED = 0;

type Row = Record<string, number | string | null>;

export interface Admin {
  id: number;
  username: string;
  password: string;
}

export interface Comment {
  id: number;
  author: string;
  text: string;
}

/** Поля строки таблицы user, кроме id из последовательности. */
export function adminRow(faker: Faker, overrides: { status?: number } = {}): Row {
  return {
    username: `admin-${uniqueTag(faker)}`,
    password_hash: ADMIN_PASSWORD_HASH,
    auth_key: faker.string.alphanumeric(AUTH_KEY_LENGTH),
    status: overrides.status ?? ADMIN_ACTIVE,
    created_at: "now",
    updated_at: "now",
  };
}

/** Что можно задать комментарию-предусловию: дату создания и админа, который его удалил. */
export interface CommentOverrides {
  createdAt?: string;
  /** Удалён этим админом сейчас; без него комментарий активен. */
  deletedBy?: number;
}

/**
 * Поля строки таблицы comment, кроме id из последовательности. Дата по умолчанию — текущее время БД:
 * комментарий-предусловие встаёт в начало ленты и списка (ярус exclusive).
 */
export function commentRow(faker: Faker, overrides: CommentOverrides = {}): Row {
  const tag = uniqueTag(faker);

  return {
    author: `${faker.person.firstName()} ${tag}`,
    text: `${faker.lorem.sentence()} ${tag}`,
    created_at: overrides.createdAt ?? "now",
    updated_at: null,
    updated_by: null,
    deleted_at: overrides.deletedBy === undefined ? null : "now",
    deleted_by: overrides.deletedBy ?? null,
  };
}

const insert = async (db: Pool, table: string, row: Row): Promise<Row> => {
  const columns = Object.keys(row);
  const placeholders = columns.map((_column, index) => `$${index + 1}`);
  const result = await db.query<Row>(
    `INSERT INTO "${table}" (${columns.join(", ")}) VALUES (${placeholders.join(", ")}) RETURNING *`,
    Object.values(row),
  );
  const [inserted] = result.rows;
  if (inserted === undefined) {
    throw new Error(`INSERT в ${table} не вернул строку`);
  }

  return inserted;
};

/** Колонки таблицы, которые заполняет фабрика: все, кроме id из последовательности. По алфавиту. */
export async function columnsToFill(db: Pool, table: string): Promise<string[]> {
  const result = await db.query<{ column_name: string }>(
    `SELECT column_name FROM information_schema.columns
     WHERE table_schema = 'public' AND table_name = $1 AND column_name <> 'id'
     ORDER BY column_name COLLATE "C"`,
    [table],
  );

  return result.rows.map((row) => row.column_name);
}

/** Админ с уникальным логином и паролем ADMIN_PASSWORD. */
export async function admin(db: Pool, faker: Faker, overrides: { status?: number } = {}): Promise<Admin> {
  const row = await insert(db, "user", adminRow(faker, overrides));

  return { id: Number(row["id"]), username: String(row["username"]), password: ADMIN_PASSWORD };
}

/** Комментарий-предусловие: вставляется в БД, а не через форму гостя. */
export async function comment(db: Pool, faker: Faker, overrides: CommentOverrides = {}): Promise<Comment> {
  const row = await insert(db, "comment", commentRow(faker, overrides));

  return { id: Number(row["id"]), author: String(row["author"]), text: String(row["text"]) };
}

/** Несколько комментариев-предусловий: вставляются по одному, каждый следующий новее предыдущего. */
export async function comments(db: Pool, faker: Faker, count: number): Promise<Comment[]> {
  if (count === 0) {
    return [];
  }
  const older = await comments(db, faker, count - 1);

  return [...older, await comment(db, faker)];
}
