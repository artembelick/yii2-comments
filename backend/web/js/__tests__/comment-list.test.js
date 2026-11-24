// @ts-check
import { describe, expect, it, onTestFinished, vi } from "vitest";

import messages from "../../../../tests/_data/messages.json" with { type: "json" };
import { createCommentList } from "../comment-list.js";
import { formatNewBanner } from "../format.js";

/** @typedef {import("../api.js").SerializedComment} SerializedComment */
/** @typedef {{ status: "active" | "deleted", page: number, perPage: number }} View */

/** @type {View} */
const FIRST_ACTIVE = { status: "active", page: 1, perPage: 3 };

/** @type {{ name: string, view: View }[]} */
const VIEWS_WITHOUT_NEWEST = [
  { name: "«Активные», стр. 2", view: { status: "active", page: 2, perPage: 3 } },
  { name: "«Удалённые», стр. 1", view: { status: "deleted", page: 1, perPage: 3 } },
];

/** @type {View} */
const FIRST_DELETED = { status: "deleted", page: 1, perPage: 3 };

/**
 * Комментарий с id и датой создания: по умолчанию id — это минута после 10:00, так что больший id новее.
 *
 * @param {number} id
 * @param {Partial<SerializedComment>} fields
 * @returns {SerializedComment}
 */
const comment = (id, fields = {}) => ({
  id,
  author: "Ян",
  text: `Комментарий ${id}`,
  status: "active",
  createdAt: `2026-11-24T10:${String(id).padStart(2, "0")}:00Z`,
  updatedAt: null,
  updatedBy: null,
  deletedBy: null,
  ...fields,
});

/**
 * @param {"comment.updated" | "comment.deleted" | "comment.restored"} type
 * @param {number} id
 * @param {Partial<SerializedComment>} fields
 * @returns {import("../ws-client.js").CommentEvent}
 */
const event = (type, id, fields = {}) => ({ type, comment: comment(id, fields) });

/**
 * @param {number} id
 * @param {Partial<SerializedComment>} fields
 * @returns {import("../ws-client.js").CommentEvent}
 */
const deleted = (id, fields = {}) => event("comment.deleted", id, { status: "deleted", deletedBy: "anna", ...fields });

/**
 * @param {number} id
 * @param {Partial<SerializedComment>} fields
 * @returns {import("../ws-client.js").CommentEvent}
 */
const restored = (id, fields = {}) => event("comment.restored", id, fields);

/**
 * Наборы «куда встаёт комментарий» для LIST-04 и LIST-05: на странице строки 30, 20, 10 (новые сверху).
 *
 * @type {{ name: string, page: number, shown: number[], id: number, fields: Partial<SerializedComment>, expected: string[] }[]}
 */
const PLACEMENTS = [
  { name: "между строками по дате", page: 1, shown: [30, 20, 10], id: 25, fields: {}, expected: ["30", "25", "20"] },
  {
    name: "новее всех на стр. 1 → первой",
    page: 1,
    shown: [30, 20, 10],
    id: 40,
    fields: {},
    expected: ["40", "30", "20"],
  },
  {
    name: "новее всех на стр. 2 → не на этой странице",
    page: 2,
    shown: [30, 20, 10],
    id: 40,
    fields: {},
    expected: ["30", "20", "10"],
  },
  {
    name: "старше всех на полной странице → не на этой",
    page: 1,
    shown: [30, 20, 10],
    id: 5,
    fields: {},
    expected: ["30", "20", "10"],
  },
  {
    name: "старше всех на неполной странице → последней",
    page: 1,
    shown: [30, 20],
    id: 5,
    fields: {},
    expected: ["30", "20", "5"],
  },
  {
    name: "та же дата, что у строки, и больший id → выше неё",
    page: 1,
    shown: [30, 20, 10],
    id: 21,
    fields: { createdAt: "2026-11-24T10:20:00Z" },
    expected: ["30", "21", "20"],
  },
];

/**
 * @param {number} id
 * @returns {import("../ws-client.js").CommentEvent}
 */
const created = (id) => ({ type: "comment.created", comment: comment(id) });

/**
 * Строка для теста: id и текст, без шаблона. Как строка собирается, проверяет row-renderer.
 *
 * @param {SerializedComment} item
 * @returns {HTMLTableRowElement}
 */
const renderRow = (item) => {
  const row = document.createElement("tr");
  row.dataset["id"] = String(item.id);
  row.dataset["createdAt"] = item.createdAt;
  row.textContent = item.text;

  return row;
};

/**
 * Список на странице с уже показанными строками и фейковыми таймерами (ИЗ-4: таймеры возвращаются после теста).
 *
 * @param {View} view
 * @param {number[]} shownIds
 * @returns {{
 *   list: ReturnType<typeof createCommentList>,
 *   table: HTMLTableElement,
 *   tbody: HTMLTableSectionElement,
 *   emptyState: HTMLParagraphElement,
 *   banner: HTMLAnchorElement,
 * }}
 */
const listOn = (view, shownIds) => {
  vi.useFakeTimers();
  onTestFinished(() => {
    vi.useRealTimers();
  });
  const table = document.createElement("table");
  const tbody = table.createTBody();
  tbody.append(...shownIds.map((id) => renderRow(comment(id))));
  table.hidden = shownIds.length === 0;
  const emptyState = document.createElement("p");
  emptyState.hidden = shownIds.length > 0;
  const banner = document.createElement("a");
  banner.hidden = true;
  const list = createCommentList({
    table,
    tbody,
    emptyState,
    view,
    renderRow,
    banner,
    bannerText: (count) => formatNewBanner(count, messages["admin.banner.new"]),
    timers: globalThis,
  });

  return { list, table, tbody, emptyState, banner };
};

/**
 * @param {HTMLTableSectionElement} tbody
 * @returns {string[]}
 */
const shownIds = (tbody) => [...tbody.rows].map((row) => String(row.dataset["id"]));

describe("comment-list", () => {
  it("UNIT-JS-LIST-01: created на «Активных», стр. 1 → строка первой и подсвечена", () => {
    const { list, tbody } = listOn(FIRST_ACTIVE, [2, 1]);

    list.apply(created(3));

    expect([shownIds(tbody), tbody.rows[0]?.dataset["highlighted"]]).toStrictEqual([["3", "2", "1"], "true"]);
  });

  it("UNIT-JS-LIST-01: подсветка снимается через 3 секунды", async () => {
    const { list, tbody } = listOn(FIRST_ACTIVE, [1]);
    list.apply(created(2));

    await vi.advanceTimersByTimeAsync(3000);

    expect(tbody.rows[0]?.dataset["highlighted"]).toBeUndefined();
  });

  it("UNIT-JS-LIST-01: подсветка держится до 3 секунд", async () => {
    const { list, tbody } = listOn(FIRST_ACTIVE, [1]);
    list.apply(created(2));

    await vi.advanceTimersByTimeAsync(2999);

    expect(tbody.rows[0]?.dataset["highlighted"]).toBe("true");
  });

  it("UNIT-JS-LIST-01: на полной странице последняя строка уходит, строк остаётся perPage", () => {
    const { list, tbody } = listOn(FIRST_ACTIVE, [3, 2, 1]);

    list.apply(created(4));

    expect(shownIds(tbody)).toStrictEqual(["4", "3", "2"]);
  });

  it("UNIT-JS-LIST-01: на пустой вкладке появляется таблица со строкой, текст пустого состояния скрыт", () => {
    const { list, table, tbody, emptyState } = listOn(FIRST_ACTIVE, []);

    list.apply(created(1));

    expect([shownIds(tbody), table.hidden, emptyState.hidden]).toStrictEqual([["1"], false, true]);
  });

  it.each(VIEWS_WITHOUT_NEWEST)(
    "UNIT-JS-LIST-02: $name + created → строка не добавлена, баннер «1 новый»",
    ({ view }) => {
      const { list, tbody, banner } = listOn(view, [2, 1]);

      list.apply(created(3));

      expect([shownIds(tbody), banner.hidden, banner.textContent]).toStrictEqual([
        ["2", "1"],
        false,
        formatNewBanner(1, messages["admin.banner.new"]),
      ]);
    },
  );

  it("UNIT-JS-LIST-02: следующий created → баннер «2 новых»", () => {
    const { list, banner } = listOn({ status: "active", page: 2, perPage: 3 }, [2, 1]);
    list.apply(created(3));

    list.apply(created(4));

    expect(banner.textContent).toBe(formatNewBanner(2, messages["admin.banner.new"]));
  });

  it("UNIT-JS-LIST-03: updated для строки на странице → строка перерисована на своём месте", () => {
    const { list, tbody } = listOn(FIRST_ACTIVE, [3, 2, 1]);

    list.apply(event("comment.updated", 2, { text: "Новый текст", updatedBy: "anna" }));

    expect([shownIds(tbody), tbody.rows[1]?.textContent]).toStrictEqual([["3", "2", "1"], "Новый текст"]);
  });

  it("UNIT-JS-LIST-03: updated для строки, которой нет на странице → ничего", () => {
    const { list, tbody, banner } = listOn(FIRST_ACTIVE, [2, 1]);

    list.apply({ type: "comment.updated", comment: comment(9) });

    expect([shownIds(tbody), banner.hidden]).toStrictEqual([["2", "1"], true]);
  });

  it("UNIT-JS-LIST-03: updated со статусом другой вкладки (снимок старше удаления) → строка убрана, а не перерисована", () => {
    const { list, tbody } = listOn(FIRST_DELETED, [3, 2, 1]);

    list.apply(event("comment.updated", 2, { text: "Текст до удаления", status: "active" }));

    expect(shownIds(tbody)).toStrictEqual(["3", "1"]);
  });

  it("UNIT-JS-LIST-04: deleted на «Активных» → строка удалена, страница не дозаполняется", () => {
    const { list, tbody } = listOn(FIRST_ACTIVE, [3, 2, 1]);

    list.apply(deleted(2));

    expect(shownIds(tbody)).toStrictEqual(["3", "1"]);
  });

  it.each(PLACEMENTS)("UNIT-JS-LIST-04: deleted на «Удалённых», $name", ({ page, shown, id, fields, expected }) => {
    const { list, tbody } = listOn({ ...FIRST_DELETED, page }, shown);

    list.apply(deleted(id, fields));

    expect(shownIds(tbody)).toStrictEqual(expected);
  });

  it("UNIT-JS-LIST-05: restored на «Удалённых» → строка удалена, страница не дозаполняется", () => {
    const { list, tbody } = listOn(FIRST_DELETED, [3, 2, 1]);

    list.apply(restored(2));

    expect(shownIds(tbody)).toStrictEqual(["3", "1"]);
  });

  it.each(PLACEMENTS)("UNIT-JS-LIST-05: restored на «Активных», $name", ({ page, shown, id, fields, expected }) => {
    const { list, tbody } = listOn({ ...FIRST_ACTIVE, page }, shown);

    list.apply(restored(id, fields));

    expect(shownIds(tbody)).toStrictEqual(expected);
  });

  it("UNIT-JS-LIST-05: восстановленный комментарий не подсвечивается и баннер не трогает", () => {
    const { list, tbody, banner } = listOn({ ...FIRST_ACTIVE, page: 2 }, [30, 20]);

    list.apply(restored(25));

    expect([shownIds(tbody), tbody.rows[1]?.dataset["highlighted"], banner.hidden]).toStrictEqual([
      ["30", "25", "20"],
      undefined,
      true,
    ]);
  });

  it("UNIT-JS-LIST-06: повторный deleted на «Удалённых» для строки, которая уже есть, ничего не дублирует", () => {
    const { list, tbody } = listOn(FIRST_DELETED, [30, 20, 10]);

    list.apply(deleted(20));

    expect(shownIds(tbody)).toStrictEqual(["30", "20", "10"]);
  });

  it("UNIT-JS-LIST-06: повторный deleted на «Активных» для строки, которой уже нет, ничего не меняет", () => {
    const { list, tbody } = listOn(FIRST_ACTIVE, [30, 10]);

    list.apply(deleted(20));

    expect(shownIds(tbody)).toStrictEqual(["30", "10"]);
  });

  it("UNIT-JS-LIST-08: удалена последняя строка → таблица скрыта, показан текст пустого состояния", () => {
    const { list, table, emptyState } = listOn(FIRST_ACTIVE, [1]);

    list.apply(deleted(1));

    expect([table.hidden, emptyState.hidden]).toStrictEqual([true, false]);
  });

  it("UNIT-JS-LIST-08: на пустую вкладку встал восстановленный → таблица показана, текст пустого состояния скрыт", () => {
    const { list, table, emptyState } = listOn(FIRST_ACTIVE, []);

    list.apply(restored(1));

    expect([table.hidden, emptyState.hidden]).toStrictEqual([false, true]);
  });

  it("UNIT-JS-LIST-06: повторный created для строки, которая уже есть, ничего не дублирует", () => {
    const { list, tbody } = listOn(FIRST_ACTIVE, [2, 1]);

    list.apply(created(2));

    expect(shownIds(tbody)).toStrictEqual(["2", "1"]);
  });

  it("UNIT-JS-LIST-06: повторный created того же комментария не увеличивает счётчик баннера", () => {
    const { list, banner } = listOn({ status: "active", page: 2, perPage: 3 }, [2, 1]);
    list.apply(created(3));

    list.apply(created(3));

    expect(banner.textContent).toBe(formatNewBanner(1, messages["admin.banner.new"]));
  });

  it("UNIT-JS-LIST-07: replaceAll перерисовывает tbody целиком и скрывает баннер", () => {
    const { list, tbody, banner } = listOn({ status: "active", page: 2, perPage: 3 }, [2, 1]);
    list.apply(created(3));

    list.replaceAll([comment(7), comment(6)]);

    expect([shownIds(tbody), banner.hidden]).toStrictEqual([["7", "6"], true]);
  });

  it("UNIT-JS-LIST-07: после replaceAll счётчик баннера начинается заново", () => {
    const { list, banner } = listOn({ status: "active", page: 2, perPage: 3 }, [2, 1]);
    list.apply(created(3));
    list.replaceAll([comment(2), comment(1)]);

    list.apply(created(4));

    expect(banner.textContent).toBe(formatNewBanner(1, messages["admin.banner.new"]));
  });

  it("UNIT-JS-LIST-07: replaceAll пустым списком → таблица скрыта, показан текст пустого состояния", () => {
    const { list, table, emptyState } = listOn(FIRST_ACTIVE, [2, 1]);

    list.replaceAll([]);

    expect([table.hidden, emptyState.hidden]).toStrictEqual([true, false]);
  });
});
