// @ts-check
import { UnauthorizedError } from "./unauthorized-error.js";
import { UnavailableError } from "./unavailable-error.js";

const UNAUTHORIZED = 401;

/**
 * @typedef {object} SerializedComment Комментарий в JSON-списке и в событиях WS (common\services\CommentSerializer).
 * @property {number} id
 * @property {string} author
 * @property {string} text
 * @property {"active" | "deleted"} status
 * @property {string} createdAt
 * @property {string | null} updatedAt
 * @property {string | null} updatedBy
 * @property {string | null} deletedBy
 */

/**
 * @typedef {object} CommentPage Страница вкладки из GET /api/comments.
 * @property {SerializedComment[]} items
 * @property {number} page
 * @property {number} pageCount
 * @property {number} totalCount
 */

/**
 * @typedef {object} Api
 * @property {() => Promise<string>} fetchToken Одноразовый токен WS (ADR 0002).
 * @property {(status: string, page: number) => Promise<CommentPage>} fetchList Страница вкладки: её клиент перечитывает после переподключения.
 */

/** @typedef {{ fetchImpl: typeof fetch, csrfToken: string, baseUrl: string }} ApiDependencies */

/**
 * @param {ApiDependencies} dependencies
 * @param {string} path
 * @param {RequestInit} init
 * @returns {Promise<Response>}
 */
const send = async ({ fetchImpl, baseUrl }, path, init) => {
  try {
    return await fetchImpl(`${baseUrl}${path}`, init);
  } catch (error) {
    throw new UnavailableError(`${path}: нет ответа`, { cause: error });
  }
};

/**
 * @param {ApiDependencies} dependencies
 * @param {string} path
 * @param {RequestInit} init
 * @returns {Promise<unknown>}
 */
const request = async (dependencies, path, init) => {
  const response = await send(dependencies, path, init);
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

/**
 * Страница списка: объект с массивом items. Поля комментариев собирает сам сервер (CommentSerializer),
 * их контракт держат REG-A-61 и UNIT-SER-01.
 *
 * @param {unknown} body
 * @returns {body is CommentPage}
 */
const isCommentPage = (body) =>
  typeof body === "object" && body !== null && "items" in body && Array.isArray(body.items);

/**
 * JSON-эндпоинты админки. Сессия идёт cookie, запись защищена CSRF-заголовком, как у форм Yii.
 * Ответ 401 — UnauthorizedError, любой другой сбой (сеть, 5xx, не тот JSON) — UnavailableError.
 *
 * @param {ApiDependencies} dependencies
 * @returns {Api}
 */
export function createApi(dependencies) {
  return {
    async fetchToken() {
      const body = await request(dependencies, "/api/ws-token", {
        method: "POST",
        headers: { "X-CSRF-Token": dependencies.csrfToken },
        credentials: "same-origin",
      });
      if (typeof body === "object" && body !== null && "token" in body && typeof body.token === "string") {
        return body.token;
      }

      throw new UnavailableError("/api/ws-token: в ответе нет token");
    },
    async fetchList(status, page) {
      const path = `/api/comments?${new URLSearchParams({ status, page: String(page) })}`;
      const body = await request(dependencies, path, { credentials: "same-origin" });
      if (isCommentPage(body)) {
        return body;
      }

      throw new UnavailableError(`${path}: в ответе нет items`);
    },
  };
}
