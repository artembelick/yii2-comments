import type { Faker } from "@faker-js/faker";
import type { Redis } from "ioredis";
import type { Pool } from "pg";

import { ADMIN_BLOCKED, rotateAdminAuthKey, setAdminStatus } from "./factories.ts";
import type { Admin } from "./factories.ts";
import { publishAccessRevoked } from "./redis.ts";

/*
 * Консольные команды admin/block и admin/password глазами WS-сервера: строка админа в БД изменилась,
 * в ws.control пришёл access.revoked. Контейнер Playwright без PHP и не может запустить ./yii, поэтому REG-WS
 * повторяют этот эффект. Сами команды на стенде запускают REG-A-09b и A-09c, их вывод и коды — UNIT-CLI.
 */

/** Как `admin/block`: статус «заблокирован» и новый auth_key, затем access.revoked. */
export async function blockAdmin(db: Pool, redis: Redis, faker: Faker, admin: Admin): Promise<void> {
  await setAdminStatus(db, admin.id, ADMIN_BLOCKED);
  await rotateAdminAuthKey(db, faker, admin.id);
  await publishAccessRevoked(redis, admin.id);
}

/** Как `admin/password`: новый auth_key (пароль WS-серверу не важен), затем access.revoked. */
export async function changeAdminPassword(db: Pool, redis: Redis, faker: Faker, admin: Admin): Promise<void> {
  await rotateAdminAuthKey(db, faker, admin.id);
  await publishAccessRevoked(redis, admin.id);
}
