import { expect } from "@playwright/test";
import type { APIRequestContext, Page } from "@playwright/test";

import { requireEnv } from "./env.ts";
import { message } from "./messages.ts";

export interface Credentials {
  username: string;
  password: string;
}

const HTTP_OK = 200;
const HTTP_FOUND = 302;
const CSRF_META = /<meta name="csrf-token" content="(?<token>[^"]+)"/u;

/** CSRF-токен из meta-тега страницы админки: его кладёт layout (registerCsrfMetaTags). */
async function csrfTokenAt(request: APIRequestContext, path: string): Promise<string> {
  const response = await request.get(`${requireEnv("ADMIN_URL")}${path}`);
  const token = CSRF_META.exec(await response.text())?.groups?.["token"];
  if (token === undefined) {
    throw new Error(`На ${path} нет meta csrf-token (ответ ${response.status()})`);
  }

  return token;
}

/**
 * Вход админа без UI (БР-3): CSRF-токен со страницы логина и POST /login через request.
 * Cookie сессии остаются в request; у `page.request` они общие с браузером страницы.
 */
export async function logIn(request: APIRequestContext, credentials: Credentials): Promise<void> {
  const csrf = await csrfTokenAt(request, "/login");
  const response = await request.post(`${requireEnv("ADMIN_URL")}/login`, {
    form: {
      "_csrf-backend": csrf,
      "LoginForm[username]": credentials.username,
      "LoginForm[password]": credentials.password,
    },
    maxRedirects: 0,
  });
  if (response.status() !== HTTP_FOUND) {
    throw new Error(`Вход ${credentials.username} не удался: ответ ${response.status()}`);
  }
}

/**
 * POST формы строки админки (правка, удаление, восстановление) без браузера: CSRF-токен со страницы списка.
 * Для REG-WS, где предмет проверки — событие, а не форма.
 */
export async function submitAdminForm(
  request: APIRequestContext,
  path: string,
  fields: Record<string, string> = {},
): Promise<void> {
  const csrf = await csrfTokenAt(request, "/");
  const response = await request.post(`${requireEnv("ADMIN_URL")}${path}`, {
    form: { "_csrf-backend": csrf, ...fields },
    maxRedirects: 0,
  });
  if (response.status() !== HTTP_FOUND) {
    throw new Error(`Действие ${path} не выполнено: ответ ${response.status()}`);
  }
}

/** Выход без UI: POST /logout с CSRF, как кнопка «Выйти». */
export async function logOut(request: APIRequestContext): Promise<void> {
  await submitAdminForm(request, "/logout");
}

/** Одноразовый токен WS для вошедшего админа: POST /api/ws-token с CSRF-заголовком, как у клиента админки. */
export async function issueWsToken(request: APIRequestContext): Promise<string> {
  const csrf = await csrfTokenAt(request, "/");
  const response = await request.post(`${requireEnv("ADMIN_URL")}/api/ws-token`, {
    headers: { "X-CSRF-Token": csrf },
  });
  const body: unknown = await response.json();
  if (response.status() !== HTTP_OK || typeof body !== "object" || body === null || !("token" in body)) {
    throw new Error(`Токен WS не выдан: ответ ${response.status()}`);
  }

  return String(body.token);
}

/**
 * Админ входит без UI, открывает страницу админки и ждёт «онлайн»: события WS, которые вызовет тест,
 * придут на уже авторизованное соединение.
 */
export async function openAdminPage(page: Page, credentials: Credentials, path = "/"): Promise<void> {
  await logIn(page.request, credentials);
  await page.goto(`${requireEnv("ADMIN_URL")}${path}`);
  await expect(page.getByRole("status", { name: message("ws.status.label") })).toHaveText(message("ws.status.online"));
}
