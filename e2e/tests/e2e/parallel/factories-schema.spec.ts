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
