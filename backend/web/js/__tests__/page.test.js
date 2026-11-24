// @ts-check
import { describe, expect, it } from "vitest";

import messages from "../../../../tests/_data/messages.json" with { type: "json" };
import { PageMarkupError } from "../page-markup-error.js";
import { readPage } from "../page.js";

const CSRF = '<meta name="csrf-token" content="csrf-of-page">';
const STATUS_TEXTS = {
  online: messages["ws.status.online"],
  reconnecting: messages["ws.status.reconnecting"],
  offline: messages["ws.status.offline"],
};
const AUDIT_TEXTS = { updated: messages["admin.audit.updated"], deleted: messages["admin.audit.deleted"] };

/** Разметка списка по частям, как её собирает backend/views/comment/index.php. Тест убирает часть, которую проверяет. */
const PARTS = {
  root: `<div data-ws-url="ws://admin.comments.localhost/ws" data-login-url="/login" data-status="deleted" data-page="2" data-per-page="20" data-time-zone="Europe/Moscow"></div>`,
  indicator: `<span data-ws-status data-text-online="${STATUS_TEXTS.online}" data-text-reconnecting="${STATUS_TEXTS.reconnecting}" data-text-offline="${STATUS_TEXTS.offline}"></span>`,
  banner: `<a data-new-banner data-pattern="${messages["admin.banner.new"]}" hidden></a>`,
  empty: "<p data-empty-state hidden></p>",
  table: "<table data-comment-list><tbody></tbody></table>",
  template: `<template data-row-template data-text-updated="${AUDIT_TEXTS.updated}" data-text-deleted="${AUDIT_TEXTS.deleted}"><tr></tr></template>`,
};
const BODY = Object.values(PARTS).join("");

/**
 * @param {string} head
 * @param {string} body
 * @returns {Document}
 */
const page = (head, body) =>
  new DOMParser().parseFromString(`<html><head>${head}</head><body>${body}</body></html>`, "text/html");

describe("page", () => {
  it("UNIT-JS-PAGE-01: настройки клиента и элементы списка берутся из разметки", () => {
    const document = page(CSRF, BODY);

    const config = readPage(document);

    expect(config).toStrictEqual({
      wsUrl: "ws://admin.comments.localhost/ws",
      loginUrl: "/login",
      csrfToken: "csrf-of-page",
      indicator: document.querySelector("[data-ws-status]"),
      statusTexts: STATUS_TEXTS,
      view: { status: "deleted", page: 2, perPage: 20 },
      timeZone: "Europe/Moscow",
      table: document.querySelector("table"),
      tbody: document.querySelector("tbody"),
      emptyState: document.querySelector("[data-empty-state]"),
      banner: document.querySelector("[data-new-banner]"),
      bannerPattern: messages["admin.banner.new"],
      template: document.querySelector("template"),
      auditTexts: AUDIT_TEXTS,
    });
  });

  it.each([
    { name: "нет блока с адресом WS", head: CSRF, body: BODY.replace(PARTS.root, "") },
    { name: "нет индикатора", head: CSRF, body: BODY.replace(PARTS.indicator, "") },
    { name: "нет CSRF-токена", head: "", body: BODY },
    { name: "нет адреса логина", head: CSRF, body: BODY.replace(' data-login-url="/login"', "") },
    { name: "нет текста состояния", head: CSRF, body: BODY.replace(" data-text-offline=", " data-other=") },
    { name: "нет часового пояса", head: CSRF, body: BODY.replace(' data-time-zone="Europe/Moscow"', "") },
    { name: "нет таблицы", head: CSRF, body: BODY.replace(PARTS.table, "") },
    { name: "нет tbody", head: CSRF, body: BODY.replace("<tbody></tbody>", "") },
    { name: "нет текста пустой вкладки", head: CSRF, body: BODY.replace(PARTS.empty, "") },
    { name: "нет баннера", head: CSRF, body: BODY.replace(PARTS.banner, "") },
    { name: "нет шаблона строки", head: CSRF, body: BODY.replace(PARTS.template, "") },
    { name: "нет текста аудита", head: CSRF, body: BODY.replace(" data-text-deleted=", " data-other=") },
  ])("UNIT-JS-PAGE-02: $name → ошибка, а не клиент без настроек", ({ head, body }) => {
    const document = page(head, body);

    expect(() => readPage(document)).toThrow(PageMarkupError);
  });
});
