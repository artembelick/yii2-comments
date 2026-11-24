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

const PLURAL_FORMS = /(?<category>zero|one|two|few|many|other)\{(?<form>[^}]*)\}/gu;

/**
 * Текст сообщения с ICU plural для числа count: `{n, plural, one{# новый} few{…} many{…}}`.
 * Форма выбирается через Intl.PluralRules('ru'), как в клиенте админки, а `#` заменяется числом.
 */
export function plural(key: string, count: number): string {
  const forms = new Map(
    [...message(key).matchAll(PLURAL_FORMS)].map((match) => [match.groups?.["category"], match.groups?.["form"]]),
  );
  const form = forms.get(new Intl.PluralRules("ru").select(count));
  if (form === undefined) {
    throw new Error(`В ${key} нет формы для ${count}`);
  }

  return form.replaceAll("#", String(count));
}
