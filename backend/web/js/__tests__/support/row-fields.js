// @ts-check

/**
 * Поля строки по data-field: имя → текст.
 *
 * @param {HTMLTableRowElement} row
 * @returns {Record<string, string | null>}
 */
export const fields = (row) =>
  Object.fromEntries(
    [...row.querySelectorAll("[data-field]")]
      .filter((cell) => cell instanceof HTMLElement)
      .map((cell) => [String(cell.dataset["field"]), cell.textContent]),
  );

/**
 * Элемент аудита строки: текст и скрыт ли он.
 *
 * @param {HTMLTableRowElement} row
 * @returns {{ text: string | null, hidden: HTMLElement["hidden"] | undefined }}
 */
export const auditOf = (row) => {
  const element = row.querySelector('[data-field="audit"]');

  return { text: element?.textContent ?? null, hidden: element instanceof HTMLElement ? element.hidden : undefined };
};
