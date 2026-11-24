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

/**
 * @param {number} id
 * @returns {SerializedComment}
 */
const comment = (id) => ({
  id,
  author: "Ян",
  text: `Комментарий ${id}`,
  status: "active",
  createdAt: "2026-11-24T11:05:00Z",
  updatedAt: null,
  updatedBy: null,
  deletedBy: null,
});

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

  it("UNIT-JS-LIST-03: updated для строки, которой нет на странице → ничего", () => {
    const { list, tbody, banner } = listOn(FIRST_ACTIVE, [2, 1]);

    list.apply({ type: "comment.updated", comment: comment(9) });

    expect([shownIds(tbody), banner.hidden]).toStrictEqual([["2", "1"], true]);
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
