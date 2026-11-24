import { readFile } from "node:fs/promises";

const isCatalog = (value: unknown): value is Record<string, string> =>
  typeof value === "object" && value !== null && Object.values(value).every((text) => typeof text === "string");

const parseCatalog = (json: string): Record<string, string> => {
  const parsed: unknown = JSON.parse(json);
  if (!isCatalog(parsed)) {
    throw new Error("tests/_data/messages.json — не словарь «ключ → текст»");
  }

  return parsed;
};

/**
 * Каталог сообщений (docs/test-plans/messages.md, ЧТ-7). Тот же файл читают PHP-тесты,
 * а UNIT-MSG-01 сверяет его с переводами Yii.
 */
const catalog = parseCatalog(await readFile(new URL("../../tests/_data/messages.json", import.meta.url), "utf8"));

/** Текст сообщения по ключу. Нет ключа — тест падает сразу, а не ищет на странице пустую строку. */
export function message(key: string): string {
  const text = catalog[key];
  if (text === undefined) {
    throw new Error(`Ключа ${key} нет в tests/_data/messages.json`);
  }

  return text;
}
