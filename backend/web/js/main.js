// @ts-check
/*
 * Entrypoint клиента админки: читает настройки страницы и связывает модули.
 * Исключён из покрытия, поэтому без ветвлений и не длиннее 60 строк (правило entrypoint-limits).
 */
import { createApi } from "./api.js";
import { createBackoff } from "./backoff.js";
import { createCommentList } from "./comment-list.js";
import { formatNewBanner } from "./format.js";
import { createListReload } from "./list-reload.js";
import { readPage } from "./page.js";
import { createRowRenderer } from "./row-renderer.js";
import { createStatusIndicator } from "./status-indicator.js";
import { createWsClient } from "./ws-client.js";

const page = readPage(document);
const indicator = createStatusIndicator({ element: page.indicator, messages: page.statusTexts });
const api = createApi({
  fetchImpl: globalThis.fetch.bind(globalThis),
  csrfToken: page.csrfToken,
  baseUrl: location.origin,
});
const rows = createRowRenderer({ template: page.template, timeZone: page.timeZone, messages: page.auditTexts });
const list = createCommentList({
  table: page.table,
  tbody: page.tbody,
  emptyState: page.emptyState,
  view: page.view,
  renderRow: (comment) => rows.render(comment),
  banner: page.banner,
  bannerText: (count) => formatNewBanner(count, page.bannerPattern),
  timers: globalThis,
});
const navigateToLogin = () => {
  location.assign(page.loginUrl);
};
const reloading = createListReload({
  list,
  fetchPage: async () => api.fetchList(page.view.status, page.view.page),
  backoff: createBackoff(),
  timers: globalThis,
  navigateToLogin,
});
createWsClient({
  url: page.wsUrl,
  api,
  backoff: createBackoff(),
  WebSocketImpl: WebSocket,
  timers: globalThis,
  onStatus: (status) => {
    indicator.set(status);
  },
  onEvent: (event) => {
    reloading.apply(event);
  },
  onReconnected: () => {
    reloading.reload();
  },
  navigateToLogin,
}).start();
