// @ts-check
/**
 * Порог покрытия PHP. Codeception пишет clover, но сам порог не проверяет,
 * поэтому `make test-unit` сверяет отчёт здесь: 100% строк и методов (ADR 0001).
 */

const FILE = /<file name="([^"]+)">([\s\S]*?)<\/file>/gu;
const FILE_METRICS = /<metrics ([^>]*)\/>\s*$/u;

/**
 * @param {string} attributes
 * @param {string} name
 * @returns {number}
 */
const metric = (attributes, name) => Number(new RegExp(String.raw`\b${name}="(\d+)"`, "u").exec(attributes)?.[1]);

/**
 * @param {string} xml содержимое clover.xml
 * @param {string} root префикс путей в отчёте, который отрезается (`/app/` в контейнере)
 * @returns {string[]} по одному сообщению на непокрытый файл
 */
export function checkClover(xml, root) {
  if (!xml.includes("<coverage")) {
    return ["отчёт покрытия не в формате clover"];
  }

  return [...xml.matchAll(FILE)].flatMap(([, path, body]) => {
    const attributes = String(FILE_METRICS.exec(String(body))?.[1]);
    const [statements, coveredStatements, methods, coveredMethods] = [
      "statements",
      "coveredstatements",
      "methods",
      "coveredmethods",
    ].map((name) => metric(attributes, name));
    const complete = statements === coveredStatements && methods === coveredMethods;

    return complete
      ? []
      : [
          `${String(path).replace(root, "")}: строки ${coveredStatements}/${statements}, методы ${coveredMethods}/${methods} — порог 100%`,
        ];
  });
}
