import { adminRow, columnsToFill, commentRow } from "../../../support/factories.ts";
import { testRunId } from "../../../support/tag.ts";
import { expect, test } from "../../../support/test.ts";

test("E2E-FIX-01: фабрики заполняют ровно колонки таблиц user и comment", async ({ db, faker }) => {
  const filled = {
    user: Object.keys(adminRow(faker)).toSorted(),
    comment: Object.keys(commentRow(faker)).toSorted(),
  };

  const schema = { user: await columnsToFill(db, "user"), comment: await columnsToFill(db, "comment") };

  expect(schema).toStrictEqual(filled);
});

test("E2E-FIX-01: метка в логине из фабрики начинается с TEST_RUN_ID стенда", ({ faker }) => {
  const runId = testRunId();

  const { username } = adminRow(faker);

  expect(username).toMatch(new RegExp(`^admin-${runId}[a-z0-9]{4}$`, "u"));
});
