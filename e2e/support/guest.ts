import type { APIRequestContext } from "@playwright/test";

import { requireEnv } from "./env.ts";

const HTTP_FOUND = 302;
const CSRF_META = /<meta name="csrf-token" content="(?<token>[^"]+)"/u;

/**
 * Отправка формы гостя без браузера: CSRF-токен со страницы ленты и POST /comments, как у формы.
 * Для REG-WS, где предмет проверки — событие, а не форма. Счётчик лимита сбрасывает фикстура `guestApi`.
 */
export async function postComment(request: APIRequestContext, fields: { name: string; text: string }): Promise<void> {
  const page = await request.get(`${requireEnv("PUBLIC_URL")}/`);
  const csrf = CSRF_META.exec(await page.text())?.groups?.["token"];
  if (csrf === undefined) {
    throw new Error(`На ленте нет meta csrf-token (ответ ${page.status()})`);
  }
  const response = await request.post(`${requireEnv("PUBLIC_URL")}/comments`, {
    form: { "_csrf-frontend": csrf, ...fields },
    maxRedirects: 0,
  });
  if (response.status() !== HTTP_FOUND) {
    throw new Error(`Комментарий не опубликован: ответ ${response.status()}`);
  }
}
