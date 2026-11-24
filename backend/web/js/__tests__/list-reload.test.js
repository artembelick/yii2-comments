// @ts-check
import { describe, expect, it, onTestFinished, vi } from "vitest";

import messages from "../../../../tests/_data/messages.json" with { type: "json" };
import { createBackoff } from "../backoff.js";
import { createCommentList } from "../comment-list.js";
import { formatNewBanner } from "../format.js";
import { createListReload } from "../list-reload.js";
import { UnauthorizedError } from "../unauthorized-error.js";
import { UnavailableError } from "../unavailable-error.js";

/** @typedef {import("../api.js").SerializedComment} SerializedComment */
/** @typedef {import("../api.js").CommentPage} CommentPage */

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
 * @param {number[]} ids
 * @returns {CommentPage}
 */
const page = (ids) => ({ items: ids.map((id) => comment(id)), page: 1, pageCount: 1, totalCount: ids.length });

/**
 * @param {SerializedComment} item
 * @returns {HTMLTableRowElement}
 */
const renderRow = (item) => {
  const row = document.createElement("tr");
  row.dataset["id"] = String(item.id);

  return row;
};

/**
 * Синхронизация над настоящим списком «Активных», стр. 1, с фейковыми таймерами (ИЗ-4).
 *
 * @param {() => Promise<CommentPage>} fetchPage очередь ответов: vi.fn().mockReturnValueOnce(…)
 * @returns {{
 *   reloading: ReturnType<typeof createListReload>,
 *   tbody: HTMLTableSectionElement,
 *   backoff: import("../backoff.js").Backoff,
 *   logins: string[],
 * }}
 */
const reloadingList = (fetchPage) => {
  vi.useFakeTimers();
  onTestFinished(() => {
    vi.useRealTimers();
  });
  const table = document.createElement("table");
  const tbody = table.createTBody();
  const banner = document.createElement("a");
  const list = createCommentList({
    table,
    tbody,
    emptyState: document.createElement("p"),
    view: { status: "active", page: 1, perPage: 3 },
    renderRow,
    banner,
    bannerText: (count) => formatNewBanner(count, messages["admin.banner.new"]),
    timers: globalThis,
  });
  const backoff = createBackoff();
  /** @type {string[]} */
  const logins = [];
  const reloading = createListReload({
    list,
    fetchPage,
    backoff,
    timers: globalThis,
    navigateToLogin: () => {
      logins.push("login");
    },
  });

  return { reloading, tbody, backoff, logins };
};

/**
 * @param {HTMLTableSectionElement} tbody
 * @returns {string[]}
 */
const shownIds = (tbody) => [...tbody.rows].map((row) => String(row.dataset["id"]));

describe("list-reload", () => {
  it("UNIT-JS-RELOAD-01: вне перечитывания событие сразу уходит в список", () => {
    const { reloading, tbody } = reloadingList(vi.fn());

    reloading.apply({ type: "comment.created", comment: comment(1) });

    expect(shownIds(tbody)).toStrictEqual(["1"]);
  });

  it("UNIT-JS-RELOAD-01: created, пришедший во время перечитывания, после ответа остаётся первой строкой", async () => {
    const response = Promise.withResolvers();
    const { reloading, tbody } = reloadingList(vi.fn().mockReturnValueOnce(response.promise));
    reloading.reload();
    reloading.apply({ type: "comment.created", comment: comment(3) });

    response.resolve(page([2, 1]));
    await vi.advanceTimersByTimeAsync(0);

    expect(shownIds(tbody)).toStrictEqual(["3", "2", "1"]);
  });

  it("UNIT-JS-RELOAD-02: ответ первого перечитывания пришёл после второго → на странице снимок второго", async () => {
    const first = Promise.withResolvers();
    const second = Promise.withResolvers();
    const { reloading, tbody } = reloadingList(
      vi.fn().mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise),
    );
    reloading.reload();
    reloading.reload();
    second.resolve(page([2, 1]));
    await vi.advanceTimersByTimeAsync(0);

    first.resolve(page([1]));
    await vi.advanceTimersByTimeAsync(0);

    expect(shownIds(tbody)).toStrictEqual(["2", "1"]);
  });

  it("UNIT-JS-RELOAD-02: отказ устаревшего перечитывания снимок нового не трогает и повтора не планирует", async () => {
    const first = Promise.withResolvers();
    const { reloading, tbody } = reloadingList(
      vi
        .fn()
        .mockReturnValueOnce(first.promise)
        .mockResolvedValueOnce(page([2, 1]))
        .mockResolvedValue(page([1])),
    );
    reloading.reload();
    reloading.reload();
    await vi.advanceTimersByTimeAsync(0);

    first.reject(new UnavailableError("/api/comments: ответ 503"));
    await vi.advanceTimersByTimeAsync(30_000);

    expect(shownIds(tbody)).toStrictEqual(["2", "1"]);
  });

  it("UNIT-JS-RELOAD-03: перечитывание не удалось → накопленные события применяются к списку", async () => {
    const response = Promise.withResolvers();
    const { reloading, tbody } = reloadingList(vi.fn().mockReturnValueOnce(response.promise));
    reloading.reload();
    reloading.apply({ type: "comment.created", comment: comment(3) });

    response.reject(new UnavailableError("/api/comments: ответ 503"));
    await vi.advanceTimersByTimeAsync(0);

    expect(shownIds(tbody)).toStrictEqual(["3"]);
  });

  it("UNIT-JS-RELOAD-03: перечитывание не удалось → через паузу backoff страница перечитывается снова", async () => {
    const unavailable = new UnavailableError("/api/comments: ответ 503");
    const { reloading, tbody } = reloadingList(
      vi
        .fn()
        .mockRejectedValueOnce(unavailable)
        .mockResolvedValueOnce(page([2, 1])),
    );
    reloading.reload();
    await vi.advanceTimersByTimeAsync(999);

    await vi.advanceTimersByTimeAsync(1);

    expect(shownIds(tbody)).toStrictEqual(["2", "1"]);
  });

  it("UNIT-JS-RELOAD-03: до конца паузы backoff повтора нет", async () => {
    const unavailable = new UnavailableError("/api/comments: ответ 503");
    const { reloading, tbody } = reloadingList(
      vi
        .fn()
        .mockRejectedValueOnce(unavailable)
        .mockResolvedValueOnce(page([2, 1])),
    );
    reloading.reload();

    await vi.advanceTimersByTimeAsync(999);

    expect(shownIds(tbody)).toStrictEqual([]);
  });

  it("UNIT-JS-RELOAD-03: удачный повтор → backoff начинается сначала", async () => {
    const unavailable = new UnavailableError("/api/comments: ответ 503");
    const { reloading, backoff } = reloadingList(
      vi
        .fn()
        .mockRejectedValueOnce(unavailable)
        .mockResolvedValueOnce(page([1])),
    );
    reloading.reload();

    await vi.advanceTimersByTimeAsync(1000);

    expect(backoff.next()).toBe(1000);
  });

  it("UNIT-JS-RELOAD-04: сессия закончилась (UnauthorizedError) → navigateToLogin()", async () => {
    const unauthorized = new UnauthorizedError("/api/comments: сессия закончилась");
    const { reloading, logins } = reloadingList(vi.fn().mockRejectedValueOnce(unauthorized));

    reloading.reload();
    await vi.advanceTimersByTimeAsync(0);

    expect(logins).toStrictEqual(["login"]);
  });

  it("UNIT-JS-RELOAD-04: после UnauthorizedError повтора нет", async () => {
    const unauthorized = new UnauthorizedError("/api/comments: сессия закончилась");
    const { reloading, tbody } = reloadingList(
      vi
        .fn()
        .mockRejectedValueOnce(unauthorized)
        .mockResolvedValue(page([1])),
    );

    reloading.reload();
    await vi.advanceTimersByTimeAsync(30_000);

    expect(shownIds(tbody)).toStrictEqual([]);
  });
});
