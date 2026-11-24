// @ts-check
import { describe, expect, it } from "vitest";

import messages from "../../../../tests/_data/messages.json" with { type: "json" };
import { PageMarkupError } from "../page-markup-error.js";
import { createRowRenderer } from "../row-renderer.js";
import { auditOf, fields } from "./support/row-fields.js";

/** Содержимое шаблона строки, как его кладёт сервер в backend/views/comment/index.php. */
const TEMPLATE = `<tr data-id="">
  <td data-field="author"></td>
  <td class="comment-text" data-field="text"></td>
  <td data-field="createdAt"></td>
  <td><span class="comment-audit" data-field="audit"></span></td>
</tr>`;

const AUDIT_TEXTS = { updated: messages["admin.audit.updated"], deleted: messages["admin.audit.deleted"] };
const UPDATED_BY_ANNA = messages["admin.audit.updated"].replace("{login}", "anna");
const DELETED_BY_BORIS = messages["admin.audit.deleted"].replace("{login}", "boris");

/** @type {import("../api.js").SerializedComment} */
const COMMENT = {
  id: 42,
  author: "Ян",
  text: "первая\nвторая",
  status: "active",
  createdAt: "2026-11-24T11:05:00Z",
  updatedAt: null,
  updatedBy: null,
  deletedBy: null,
};

/**
 * @param {string} markup
 * @returns {HTMLTemplateElement}
 */
const template = (markup = TEMPLATE) => {
  const element = document.createElement("template");
  element.innerHTML = markup;

  return element;
};

/**
 * @param {import("../api.js").SerializedComment} comment
 * @returns {HTMLTableRowElement}
 */
const render = (comment) =>
  createRowRenderer({ template: template(), timeZone: "Europe/Moscow", messages: AUDIT_TEXTS }).render(comment);

/** @type {{ name: string, audit: Partial<import("../api.js").SerializedComment>, text: string, hidden: boolean }[]} */
const AUDITS = [
  {
    name: "updatedBy → «изменил: login»",
    audit: { updatedAt: "2026-11-24T12:00:00Z", updatedBy: "anna" },
    text: UPDATED_BY_ANNA,
    hidden: false,
  },
  {
    name: "deletedBy → «удалил: login»",
    audit: { status: "deleted", deletedBy: "boris" },
    text: DELETED_BY_BORIS,
    hidden: false,
  },
  {
    name: "updatedBy и deletedBy → обе строки",
    audit: { status: "deleted", updatedAt: "2026-11-24T12:00:00Z", updatedBy: "anna", deletedBy: "boris" },
    text: `${UPDATED_BY_ANNA}\n${DELETED_BY_BORIS}`,
    hidden: false,
  },
  {
    name: "логин с $& и $' → подставлен как есть, без шаблонов replace",
    audit: { updatedAt: "2026-11-24T12:00:00Z", updatedBy: "a$&b$'c" },
    text: messages["admin.audit.updated"].split("{login}").join("a$&b$'c"),
    hidden: false,
  },
  { name: "null → аудит пустой и скрыт", audit: {}, text: "", hidden: true },
];

describe("row-renderer", () => {
  it("UNIT-JS-ROW-01: клонирует шаблон, ставит data-id и заполняет автора, текст и дату", () => {
    const row = render(COMMENT);

    expect([row.dataset["id"], fields(row)]).toStrictEqual([
      "42",
      { author: "Ян", text: "первая\nвторая", createdAt: "24.11.2026 14:05", audit: "" },
    ]);
  });

  it("UNIT-JS-ROW-01: каждая строка — свой клон, шаблон не меняется", () => {
    const source = template();
    const renderer = createRowRenderer({ template: source, timeZone: "Europe/Moscow", messages: AUDIT_TEXTS });

    const rows = [renderer.render(COMMENT), renderer.render({ ...COMMENT, id: 43 })];

    expect([rows.map((row) => row.dataset["id"]), source.innerHTML]).toStrictEqual([["42", "43"], TEMPLATE]);
  });

  it.each([
    { name: "в шаблоне нет <tr>", markup: "<div></div>" },
    { name: "в шаблоне нет поля автора", markup: TEMPLATE.replace('data-field="author"', "") },
  ])("UNIT-JS-ROW-01: $name → ошибка разметки, а не строка с пустыми ячейками", ({ markup }) => {
    const renderer = createRowRenderer({
      template: template(markup),
      timeZone: "Europe/Moscow",
      messages: AUDIT_TEXTS,
    });

    expect(() => renderer.render(COMMENT)).toThrow(PageMarkupError);
  });

  it("UNIT-JS-ROW-02: HTML в имени и тексте остаётся текстом, новых элементов в строке нет", () => {
    const row = render({
      ...COMMENT,
      author: '<img src=x onerror="window.__xss=1">',
      text: "<script>window.__xss=1</script>",
    });

    expect([row.querySelectorAll("*").length, fields(row)["author"], fields(row)["text"]]).toStrictEqual([
      template().content.querySelectorAll("tr *").length,
      '<img src=x onerror="window.__xss=1">',
      "<script>window.__xss=1</script>",
    ]);
  });

  it.each(AUDITS)("UNIT-JS-ROW-03: $name", ({ audit, text, hidden }) => {
    const row = render({ ...COMMENT, ...audit });

    expect(auditOf(row)).toStrictEqual({ text, hidden });
  });
});
