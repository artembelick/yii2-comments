// @ts-check
import { PageMarkupError } from "./page-markup-error.js";

/** @typedef {import("./status-indicator.js").WsStatus} WsStatus */
/** @typedef {import("./row-renderer.js").AuditTexts} AuditTexts */
/** @typedef {import("./comment-list.js").ListView} ListView */

/**
 * @typedef {object} PageConfig Настройки клиента и элементы списка, которые сервер положил в разметку.
 * @property {string} wsUrl
 * @property {string} loginUrl
 * @property {string} csrfToken
 * @property {HTMLElement} indicator
 * @property {Record<WsStatus, string>} statusTexts
 * @property {ListView} view
 * @property {string} timeZone часовой пояс отображения дат
 * @property {HTMLTableElement} table
 * @property {HTMLTableSectionElement} tbody
 * @property {HTMLElement} emptyState
 * @property {HTMLElement} banner
 * @property {string} bannerPattern ICU plural `admin.banner.new`
 * @property {HTMLTemplateElement} template
 * @property {AuditTexts} auditTexts
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
 * @template {Element} T
 * @param {ParentNode} root
 * @param {string} selector
 * @param {abstract new () => T} type
 * @returns {T}
 */
const find = (root, selector, type) => {
  const element = root.querySelector(selector);
  if (!(element instanceof type)) {
    throw new PageMarkupError(`В разметке списка нет элемента ${selector}`);
  }

  return element;
};

/**
 * Читает настройки клиента со страницы списка (backend/views/comment/index.php). Чего-то нет — ошибка сразу,
 * а не клиент, который молча ходит не туда.
 *
 * @param {Document} document
 * @returns {PageConfig}
 */
export function readPage(document) {
  const root = find(document, "[data-ws-url]", HTMLElement);
  const indicator = find(document, "[data-ws-status]", HTMLElement);
  const table = find(document, "table[data-comment-list]", HTMLTableElement);
  const banner = find(document, "[data-new-banner]", HTMLElement);
  const template = find(document, "template[data-row-template]", HTMLTemplateElement);

  return {
    wsUrl: required(root, "wsUrl"),
    loginUrl: required(root, "loginUrl"),
    csrfToken: find(document, 'meta[name="csrf-token"]', HTMLMetaElement).content,
    indicator,
    statusTexts: {
      online: required(indicator, "textOnline"),
      reconnecting: required(indicator, "textReconnecting"),
      offline: required(indicator, "textOffline"),
    },
    view: {
      status: required(root, "status"),
      page: Number(required(root, "page")),
      perPage: Number(required(root, "perPage")),
    },
    timeZone: required(root, "timeZone"),
    table,
    tbody: find(table, "tbody", HTMLTableSectionElement),
    emptyState: find(document, "[data-empty-state]", HTMLElement),
    banner,
    bannerPattern: required(banner, "pattern"),
    template,
    auditTexts: { updated: required(template, "textUpdated"), deleted: required(template, "textDeleted") },
  };
}
