// @ts-check
import { describe, expect, it } from "vitest";

import messages from "../../../../tests/_data/messages.json" with { type: "json" };
import { PageMarkupError } from "../page-markup-error.js";
import { readPage } from "../page.js";

const CSRF = '<meta name="csrf-token" content="csrf-of-page">';
const ROOT = '<div data-ws-url="ws://admin.comments.localhost/ws" data-login-url="/login"></div>';
const STATUS_TEXTS = {
  online: messages["ws.status.online"],
  reconnecting: messages["ws.status.reconnecting"],
  offline: messages["ws.status.offline"],
};
const INDICATOR = `<span data-ws-status data-text-online="${STATUS_TEXTS.online}" data-text-reconnecting="${STATUS_TEXTS.reconnecting}" data-text-offline="${STATUS_TEXTS.offline}"></span>`;

/**
 * @param {string} head
 * @param {string} body
 * @returns {Document}
 */
const page = (head, body) =>
  new DOMParser().parseFromString(`<html><head>${head}</head><body>${body}</body></html>`, "text/html");

describe("page", () => {
  it("UNIT-JS-PAGE-01: адрес WS, адрес логина, CSRF-токен, индикатор и его тексты берутся из разметки", () => {
    const document = page(CSRF, ROOT + INDICATOR);

    const config = readPage(document);

    expect(config).toStrictEqual({
      wsUrl: "ws://admin.comments.localhost/ws",
      loginUrl: "/login",
      csrfToken: "csrf-of-page",
      indicator: document.querySelector("[data-ws-status]"),
      statusTexts: STATUS_TEXTS,
    });
  });

  it.each([
    { name: "нет блока с адресом WS", head: CSRF, body: INDICATOR },
    { name: "нет индикатора", head: CSRF, body: ROOT },
    { name: "нет CSRF-токена", head: "", body: ROOT + INDICATOR },
    {
      name: "нет адреса логина",
      head: CSRF,
      body: `<div data-ws-url="ws://admin.comments.localhost/ws"></div>${INDICATOR}`,
    },
    {
      name: "нет текста состояния",
      head: CSRF,
      body: `${ROOT}<span data-ws-status data-text-online="${STATUS_TEXTS.online}"></span>`,
    },
  ])("UNIT-JS-PAGE-02: $name → ошибка, а не клиент без настроек", ({ head, body }) => {
    const document = page(head, body);

    expect(() => readPage(document)).toThrow(PageMarkupError);
  });
});
