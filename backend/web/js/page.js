// @ts-check
import { PageMarkupError } from "./page-markup-error.js";

/** @typedef {import("./status-indicator.js").WsStatus} WsStatus */

/**
 * @typedef {object} PageConfig Настройки клиента, которые сервер положил в разметку списка.
 * @property {string} wsUrl
 * @property {string} loginUrl
 * @property {string} csrfToken
 * @property {HTMLElement} indicator
 * @property {Record<WsStatus, string>} statusTexts
 */

/**
 * @param {HTMLElement} element
 * @param {string} key имя в dataset: data-login-url → loginUrl
 * @returns {string}
 */
const required = (element, key) => {
  const value = element.dataset[key];
  if (value === undefined) {
    throw new PageMarkupError(`В разметке списка нет атрибута data-* «${key}»`);
  }

  return value;
};

/**
 * Читает настройки клиента со страницы списка (backend/views/comment/index.php). Чего-то нет — ошибка сразу,
 * а не клиент, который молча ходит не туда.
 *
 * @param {Document} document
 * @returns {PageConfig}
 */
export function readPage(document) {
  const root = document.querySelector("[data-ws-url]");
  const indicator = document.querySelector("[data-ws-status]");
  const csrf = document.querySelector('meta[name="csrf-token"]');
  if (!(root instanceof HTMLElement) || !(indicator instanceof HTMLElement) || !(csrf instanceof HTMLMetaElement)) {
    throw new PageMarkupError("На странице нет блока WS, индикатора связи или CSRF-токена");
  }

  return {
    wsUrl: required(root, "wsUrl"),
    loginUrl: required(root, "loginUrl"),
    csrfToken: csrf.content,
    indicator,
    statusTexts: {
      online: required(indicator, "textOnline"),
      reconnecting: required(indicator, "textReconnecting"),
      offline: required(indicator, "textOffline"),
    },
  };
}
