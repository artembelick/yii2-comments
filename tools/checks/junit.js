// @ts-check
/**
 * Строгость PHPUnit поверх Codeception. Codeception завершается с ошибкой только на error и failure,
 * а тесты без проверок, пропуски и предупреждения пропускает. Здесь они роняют прогон по junit-отчёту
 * (rules.md: ПР-5 и раздел 7).
 */

const TEST_CASE = /<testcase\b([^>]*?)(?:\/>|>([\s\S]*?)<\/testcase>)/gu;

/** @type {ReadonlyArray<[RegExp, string]>} */
const FAULTS = [
  [/<error>Useless Test<\/error>/u, "тест без проверок (ПР-5)"],
  [/<skipped\b/u, "тест пропущен (skip запрещён)"],
  [/<warning\b/u, "предупреждение PHPUnit"],
];

/**
 * @param {string} attributes
 * @param {string} name
 * @returns {string}
 */
const attribute = (attributes, name) => String(new RegExp(String.raw`\b${name}="([^"]*)"`, "u").exec(attributes)?.[1]);

/**
 * @param {string} xml содержимое junit.xml
 * @returns {string[]} по сообщению на каждую найденную проблему
 */
export function checkJunit(xml) {
  if (!xml.includes("<testsuites")) {
    return ["отчёт тестов не в формате junit"];
  }

  return [...xml.matchAll(TEST_CASE)].flatMap(([, attributes, body = ""]) =>
    FAULTS.filter(([pattern]) => pattern.test(body)).map(
      ([, reason]) => `${attribute(String(attributes), "class")}::${attribute(String(attributes), "name")}: ${reason}`,
    ),
  );
}
