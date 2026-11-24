// @ts-check
/*
 * Entrypoint клиента админки: читает настройки страницы и связывает модули.
 * Исключён из покрытия, поэтому без ветвлений и не длиннее 60 строк (правило entrypoint-limits).
 */
import { createApi } from "./api.js";
import { createBackoff } from "./backoff.js";
import { readPage } from "./page.js";
import { createStatusIndicator } from "./status-indicator.js";
import { createWsClient } from "./ws-client.js";

const page = readPage(document);
const indicator = createStatusIndicator({ element: page.indicator, messages: page.statusTexts });

createWsClient({
  url: page.wsUrl,
  api: createApi({ fetchImpl: globalThis.fetch.bind(globalThis), csrfToken: page.csrfToken, baseUrl: location.origin }),
  backoff: createBackoff(),
  WebSocketImpl: WebSocket,
  timers: globalThis,
  onStatus: (status) => {
    indicator.set(status);
  },
  navigateToLogin: () => {
    location.assign(page.loginUrl);
  },
}).start();
