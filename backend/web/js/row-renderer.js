// @ts-check
import { formatDate } from "./format.js";
import { PageMarkupError } from "./page-markup-error.js";

/** @typedef {import("./api.js").SerializedComment} SerializedComment */

/**
 * @typedef {object} AuditTexts Тексты аудита из каталога, `{login}` заменяется логином.
 * @property {string} updated `admin.audit.updated`
 * @property {string} deleted `admin.audit.deleted`
 */

/**
 * Поле строки по data-field. Шаблон кладёт сервер, поэтому поля нет — ошибка разметки, а не пустая ячейка.
 *
 * @param {Element} row
 * @param {string} name
 * @returns {HTMLElement}
 */
const field = (row, name) => {
  const element = row.querySelector(`[data-field="${name}"]`);
  if (!(element instanceof HTMLElement)) {
    throw new PageMarkupError(`В шаблоне строки нет поля ${name}`);
  }

  return element;
};

/**
 * Действия строки по статусу комментария: в шаблоне блоки `data-actions` для обоих статусов, лишний убирается.
 * В адресах ссылок и форм шаблона вместо id стоит `{id}`.
 *
 * @param {HTMLTableRowElement} row
 * @param {SerializedComment} comment
 */
const showActionsFor = (row, comment) => {
  /** @type {NodeListOf<HTMLElement>} */
  const blocks = row.querySelectorAll("[data-actions]");
  for (const block of blocks) {
    if (block.dataset["actions"] !== comment.status) {
      block.remove();
    }
  }
  const id = String(comment.id);
  for (const link of row.querySelectorAll("a[href]")) {
    link.setAttribute("href", String(link.getAttribute("href")).replace("{id}", id));
  }
  for (const form of row.querySelectorAll("form[action]")) {
    form.setAttribute("action", String(form.getAttribute("action")).replace("{id}", id));
  }
};

/**
 * Строки списка админки из `<template>`, который сервер кладёт рядом с таблицей: строка из JS устроена
 * так же, как строка из PHP. Всё пишется через textContent: разметка в комментарии остаётся текстом (ADR 0002).
 *
 * @param {{ template: HTMLTemplateElement, timeZone: string, messages: AuditTexts }} dependencies
 * @returns {{ render(comment: SerializedComment): HTMLTableRowElement }}
 */
export function createRowRenderer({ template, timeZone, messages }) {
  return {
    render(comment) {
      const row = template.content.firstElementChild?.cloneNode(true);
      if (!(row instanceof HTMLTableRowElement)) {
        throw new PageMarkupError("В шаблоне строки нет <tr>");
      }
      // Логин подставляется функцией: строка замены разобрала бы в нём $& и $' как шаблоны replace.
      const { updatedBy, deletedBy } = comment;
      const audit = [
        updatedBy === null ? "" : messages.updated.replace("{login}", () => updatedBy),
        deletedBy === null ? "" : messages.deleted.replace("{login}", () => deletedBy),
      ].filter((line) => line !== "");
      row.dataset["id"] = String(comment.id);
      row.dataset["createdAt"] = comment.createdAt;
      showActionsFor(row, comment);
      field(row, "author").textContent = comment.author;
      field(row, "text").textContent = comment.text;
      field(row, "createdAt").textContent = formatDate(comment.createdAt, timeZone);
      field(row, "audit").textContent = audit.join("\n");
      field(row, "audit").hidden = audit.length === 0;

      return row;
    },
  };
}
