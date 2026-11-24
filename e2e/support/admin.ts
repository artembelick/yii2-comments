import type { APIRequestContext } from "@playwright/test";

import { requireEnv } from "./env.ts";

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
