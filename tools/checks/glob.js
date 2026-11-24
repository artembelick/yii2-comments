// @ts-check
/**
 * Минимальный glob: `**` (любые каталоги), `*` (часть имени), `{a,b}` (варианты).
 * Пути — относительные, через `/`.
 *
 * @param {string} glob
 * @returns {RegExp}
 */
export function globToRegExp(glob) {
  const source = glob
    .replaceAll(/[.+^$()|[\]\\]/gu, String.raw`\$&`)
    .replaceAll(/\{([^}]*)\}/gu, (_all, variants) => `(?:${String(variants).split(",").join("|")})`)
    .replaceAll("**/", "\0")
    .replaceAll("**", "\u0001")
    .replaceAll("*", "[^/]*")
    .replaceAll("\0", "(?:.*/)?")
    .replaceAll("\u0001", ".*");

  return new RegExp(`^${source}$`, "u");
}
