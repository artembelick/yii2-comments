#!/usr/bin/env node
// @ts-check
// Entry point: передаёт аргументы и вывод процесса в cli.js. Логика — в cli.js под тестами.
import { run } from "./cli.js";

/** Первые два элемента argv — путь к node и к этому скрипту. */
const ARGS_OFFSET = 2;

process.exitCode = await run({
  root: process.cwd(),
  argv: process.argv.slice(ARGS_OFFSET),
  write: (chunk) => {
    process.stdout.write(chunk);
  },
});
