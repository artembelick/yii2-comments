// @ts-check
import { describe, expect, it, vi } from "vitest";

import { createApi } from "../api.js";
import { UnauthorizedError } from "../unauthorized-error.js";
import { UnavailableError } from "../unavailable-error.js";

const BASE_URL = "http://admin.comments.localhost";
const CSRF = "csrf-of-page";

/**
 * Мок fetch, который отвечает статусом и телом-строкой.
 *
 * @param {number} status
 * @param {string} body
 * @returns {import("vitest").Mock<typeof fetch>}
 */
const replying = (status, body) =>
  vi.fn().mockResolvedValue(new Response(body, { status, headers: { "Content-Type": "application/json" } }));

describe("api", () => {
  it("UNIT-JS-API-01: fetchToken отправляет POST /api/ws-token с CSRF-заголовком и cookie сессии, возвращает token", async () => {
    const fetchImpl = replying(200, '{"token":"token-1"}');
    const api = createApi({ fetchImpl, csrfToken: CSRF, baseUrl: BASE_URL });

    const token = await api.fetchToken();

    expect(token).toBe("token-1");
    expect(fetchImpl).toHaveBeenCalledWith(`${BASE_URL}/api/ws-token`, {
      method: "POST",
      headers: { "X-CSRF-Token": CSRF },
      credentials: "same-origin",
    });
  });

  it("UNIT-JS-API-03: ответ 401 → UnauthorizedError", async () => {
    const api = createApi({
      fetchImpl: replying(401, '{"error":"unauthorized"}'),
      csrfToken: CSRF,
      baseUrl: BASE_URL,
    });

    await expect(api.fetchToken()).rejects.toBeInstanceOf(UnauthorizedError);
  });

  it.each([
    { name: "ответ 503", fetchImpl: replying(503, '{"error":"unavailable"}') },
    { name: "ответ 500 без JSON", fetchImpl: replying(500, "<h1>Internal Server Error</h1>") },
    { name: "сетевая ошибка", fetchImpl: vi.fn().mockRejectedValue(new TypeError("Failed to fetch")) },
    { name: "невалидный JSON при 200", fetchImpl: replying(200, '{"token":') },
    { name: "в JSON нет token", fetchImpl: replying(200, '{"error":"unavailable"}') },
    { name: "token не строка", fetchImpl: replying(200, '{"token":42}') },
    { name: "JSON null", fetchImpl: replying(200, "null") },
    { name: "JSON строка", fetchImpl: replying(200, '"token-1"') },
  ])("UNIT-JS-API-04: $name → UnavailableError", async ({ fetchImpl }) => {
    const api = createApi({ fetchImpl, csrfToken: CSRF, baseUrl: BASE_URL });

    await expect(api.fetchToken()).rejects.toBeInstanceOf(UnavailableError);
  });
});
