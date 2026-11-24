import { requireEnv } from "../../../support/env.ts";
import { adminRow, columnsToFill, commentRow } from "../../../support/factories.ts";
import { expect, test } from "../../../support/test.ts";

test("E2E-FIX-01: фабрики заполняют ровно колонки таблиц user и comment", async ({ db, faker }) => {
  const filled = {
    user: Object.keys(adminRow(faker)).toSorted(),
    comment: Object.keys(commentRow(faker)).toSorted(),
  };

  const schema = { user: await columnsToFill(db, "user"), comment: await columnsToFill(db, "comment") };

  expect(schema).toStrictEqual(filled);
});

test("E2E-FIX-01: метка в логине из фабрики — TEST_RUN_ID стенда и 4 символа [a-z0-9], как у PHP-фикстур", ({
  faker,
}) => {
  const runId = requireEnv("TEST_RUN_ID");

  const { username } = adminRow(faker);

  expect(username).toMatch(new RegExp(`^admin-${runId}[a-z0-9]{4}$`, "u"));
});
