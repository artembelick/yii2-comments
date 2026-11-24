// @ts-check
import { describe, expect, it } from "vitest";

import messages from "../../../../tests/_data/messages.json" with { type: "json" };
import { createStatusIndicator } from "../status-indicator.js";

const STATUS_TEXTS = {
  online: messages["ws.status.online"],
  reconnecting: messages["ws.status.reconnecting"],
  offline: messages["ws.status.offline"],
};

/** @type {import("../status-indicator.js").WsStatus[]} */
const STATES = ["online", "reconnecting", "offline"];

describe("status-indicator", () => {
  it.each(STATES)("UNIT-JS-IND-01: set('%s') → текст из каталога и data-state", (state) => {
    const element = document.createElement("span");
    const indicator = createStatusIndicator({ element, messages: STATUS_TEXTS });

    indicator.set(state);

    expect([element.textContent, element.dataset["state"]]).toStrictEqual([STATUS_TEXTS[state], state]);
  });
});
