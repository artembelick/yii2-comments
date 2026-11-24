// @ts-check
import { UnauthorizedError } from "./unauthorized-error.js";
import { UnavailableError } from "./unavailable-error.js";

const UNAUTHORIZED = 401;

/**
 * @typedef {object} Api
 * @property {() => Promise<string>} fetchToken Одноразовый токен WS (ADR 0002).
 */

/**
 * JSON-эндпоинты админки. Сессия идёт cookie, запись защищена CSRF-заголовком, как у форм Yii.
 * Ответ 401 — UnauthorizedError, любой другой сбой (сеть, 5xx, не тот JSON) — UnavailableError.
 *
 * @param {{ fetchImpl: typeof fetch, csrfToken: string, baseUrl: string }} dependencies
 * @returns {Api}
 */
export function createApi({ fetchImpl, csrfToken, baseUrl }) {
  /**
   * @param {string} path
   * @param {RequestInit} init
   * @returns {Promise<Response>}
   */
  const send = async (path, init) => {
    try {
      return await fetchImpl(`${baseUrl}${path}`, init);
    } catch (error) {
      throw new UnavailableError(`${path}: нет ответа`, { cause: error });
    }
  };

  /**
   * @param {string} path
   * @param {RequestInit} init
   * @returns {Promise<unknown>}
   */
  const request = async (path, init) => {
    const response = await send(path, init);
    if (response.status === UNAUTHORIZED) {
      throw new UnauthorizedError(`${path}: сессия закончилась`);
    }
    if (!response.ok) {
      throw new UnavailableError(`${path}: ответ ${response.status}`);
    }
    try {
      return await response.json();
    } catch (error) {
      throw new UnavailableError(`${path}: ответ не JSON`, { cause: error });
    }
  };

  return {
    async fetchToken() {
      const body = await request("/api/ws-token", {
        method: "POST",
        headers: { "X-CSRF-Token": csrfToken },
        credentials: "same-origin",
      });
      if (typeof body === "object" && body !== null && "token" in body && typeof body.token === "string") {
        return body.token;
      }

      throw new UnavailableError("/api/ws-token: в ответе нет token");
    },
  };
}
