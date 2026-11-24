// @ts-check

const PLURAL_FORMS = /(zero|one|two|few|many|other)\{([^}]*)\}/gu;

/**
 * Дата в формате списка админки `dd.MM.yyyy HH:mm`, как у Yii formatter (php:d.m.Y H:i).
 *
 * @param {string} iso дата из JSON: ISO 8601 UTC
 * @param {string} timeZone часовой пояс отображения
 * @returns {string}
 */
export function formatDate(iso, timeZone) {
  const parts = new Intl.DateTimeFormat("ru-RU", {
    timeZone,
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).formatToParts(new Date(iso));
  /** @type {Partial<Record<Intl.DateTimeFormatPartTypes, string>>} */
  const part = Object.fromEntries(parts.map(({ type, value }) => [type, value]));

  return `${part.day}.${part.month}.${part.year} ${part.hour}:${part.minute}`;
}

/**
 * Текст баннера «N новых» из ICU plural каталога (`admin.banner.new`): форма по Intl.PluralRules('ru'),
 * `#` заменяется числом. Для целых n в русском хватает форм one, few и many — они в каталоге есть все.
 *
 * @param {number} count
 * @param {string} pattern `{n, plural, one{# новый комментарий} few{…} many{…}}`
 * @returns {string}
 */
export function formatNewBanner(count, pattern) {
  const forms = new Map([...pattern.matchAll(PLURAL_FORMS)].map(([, category, form]) => [category, form]));

  return String(forms.get(new Intl.PluralRules("ru").select(count))).replaceAll("#", String(count));
}
