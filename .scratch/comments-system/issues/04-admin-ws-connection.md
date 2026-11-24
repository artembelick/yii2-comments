# 04: WS-подключение админки

**What to build:** админка получает одноразовый токен, подключается к WS-серверу на Workerman, авторизуется первым сообщением и показывает индикатор «онлайн». Сервер проверяет `Origin`, гасит токен, закрывает неавторизованные соединения и нарушения протокола своими кодами, клиент переподключается с backoff. Решения: `docs/adr/0002-websocket-one-time-token.md`.

**Blocked by:** 03.

**Status:** done

**Внешний цикл (пишется первым):**
- E2E-01: к концу среза зелёные шаги 1–5, тест падает на шаге 6 (строка не появляется вживую);
- SMOKE-04.

**Внутренний цикл (TDD, по одному тесту):**
- Unit PHP: UNIT-TOK-01…06, WTC-01…03, REG-01…04, ORIG-01…02, CHK-01…03, MH-01…09, ACL-02
- Unit JS: UNIT-JS-BO-01…02, JS-API-01, JS-API-03, JS-API-04, JS-WS-01, JS-WS-02, JS-WS-05…07, JS-WS-09, JS-IND-01
- Regression: REG-A-21 (токен), A-60, A-60a, REG-WS-01…05 (с WS-04a), WS-10

**Готово, когда:**
- [x] Контейнер `ws` запускает настоящий Workerman, тонкий слой `ws/runtime` проходит линтер «без ветвлений, до 60 строк»
- [x] `main.js` связывает модули и проходит тот же линтер
- [x] Перечисленные тесты зелёные, тесты срезов 02–03 остаются зелёными, покрытие 100%
- [x] Флаг `--pass-with-no-tests` у Playwright убран из Makefile везде, кроме `test-regression-ws-exclusive`: пустой проект теперь значит сломанный `testDir`. В ярусе exclusive REG-WS по плану только WS-06 и WS-07 (`docs/test-plans/regression.md:19`), оба про рассылку событий из среза 05, поэтому снятие флага у этой цели перенесено в тикет 05
- [x] `make check` и CI зелёные, кроме E2E-01, который падает на шаге 6 (CI-ран 45444867514 на `61404bf` зелёный, кроме ожидаемого падения E2E-01 на шаге 6)
- [x] `/code-review` среза пройден (ревью проведено, находки исправлены)

## Comments

**Реализация (ветка `slice/04-admin-ws-connection`).** Внешний цикл: SMOKE-04 написан первым и падал на отсутствии индикатора, E2E-01 теперь проходит шаги 1–5 и падает на шаге 6 (строка К1 не появляется вживую). `make check` проходит lint, unit (покрытие PHP и JS 100%), smoke, REST-regression и REG-WS и падает только на E2E-01, шаг 6. CI не запускался, ветка не запушена.

Что сделано:
- `common\security\WsTokenService` (+ `WsTokenClaims`): токен 32 байта base64url, `ws-token:` + SET NX EX 60, гашение GETDEL. `RedisClientInterface` дополнен `setNxEx` и `getDel`.
- `backend\controllers\WsTokenController` на `POST /api/ws-token`; `AdminAccessControl` отдаёт гостю на `/api/*` 401 с JSON.
- WS-сервер: `ws\ConnectionRegistry`, `OriginPolicy`, `ActiveAdminChecker`, `MessageHandler`, коды в `CloseCode`. `ws/runtime` — `server.php` (Yii внутри процесса, `ws/config/main.php`), адаптеры `WorkermanConnection` и `WorkermanTimer`, всё без ветвлений.
- JS-клиент: `backoff.js`, `api.js` (+ `unauthorized-error.js`, `unavailable-error.js`), `status-indicator.js`, `ws-client.js`, `page.js`, `main.js`. Индикатор «Связь» на списке, тексты `ws.status.reconnecting` и `ws.status.offline` добавлены в каталог.
- REG-WS на Node-клиенте `ws` (`e2e/support/ws.ts`), фикстура `adminApi` и вход без UI (`e2e/support/admin.ts`).
- `passWithNoTests` у Vitest и `--pass-with-no-tests` у Playwright убраны.

Отклонения от плана (внесены в `docs/test-plans/unit.md`):
- `MessageHandler::onConnect(c, ?origin)`: Origin виден только в handshake, а runtime без ветвлений, поэтому проверка Origin в обработчике. В `ConnectionInterface` добавлен `reject()` (HTTP 403), новый UNIT-MH-10.
- В тестах `MessageHandler` реестр, `WsTokenService` и `ActiveAdminChecker` настоящие (классы final), мокаются Redis, репозиторий и БД; соединение и таймер — фейки из `ws/tests/_support`. Часы не нужны.
- UNIT-MH-05 дополнен: Redis недоступен при `consume` → 1011 (иначе исключение в колбэке остановило бы Workerman).
- Таймер тишины ставится уже в `onConnect` (MH-01: 5 и 60 секунд).
- `page.js` (UNIT-JS-PAGE-01, 02): чтение data-атрибутов вынесено из `main.js`, проверка наличия — это ветвления.
- Ошибки API в своих модулях: линтер допускает один класс на файл.
- `.oxlintrc.json`: `no-void` разрешён как оператор-выражение (так `no-floating-promises` помечает намеренно не ожидаемый промис); `vitest/prefer-lowercase-title` допускает префикс `UNIT-`; `vitest/require-hook` выключен для `main.js` и `e2e/tests`, `unicorn/no-array-for-each` — для `e2e/tests` (провайдеры Playwright через forEach). У каждого исключения комментарий.
- Workerman пишет лог в stdout сам (без daemonize), `Worker::$logFile` = `/dev/null`: с `/dev/stderr` обработчик ошибок Yii останавливал процесс на старте.

Что осталось открытым:
- Пункт про `--pass-with-no-tests` выполнен не полностью: флаг оставлен только у `test-regression-ws-exclusive`. В ярусе exclusive REG-WS сценарии WS-06 и WS-07, они про рассылку событий (срез 05); сейчас такой тест прошёл бы, ничего не проверив. В тикет 05 добавлен пункт снять флаг.
- Повторный прогон e2e и REG-WS на том же стенде без `make stand-down` падает на уникальном логине админа из фабрики (seed Faker из имени теста), как и в REST-regression (тикеты 02, 03).


**После ревью среза.** Исправлено:
- REG-WS-04 разделён на два сценария плана: WS-04 (второе соединение закрыто 4401) и WS-04a (после этого первое отвечает pong). Соединения REG-WS открывает фикстура `ws` и закрывает после теста, уборки в теле тестов нет.
- UNIT-JS-WS-05 и WS-07: граница паузы проверяется двумя тестами («до паузы соединения нет», «после паузы есть»). «Ping разорванного соединения не уходит» записан в строку WS-05 плана.
- `page.js` бросает одну `PageMarkupError` (свой модуль), тест проверяет её; тексты статусов в `page.test.js` из `messages.json`. `ActiveAdminCheckerTest` (CHK-01) на stub, MH-06 — три блока.
- `ws-client.js`: `stop()` во время запроса токена — соединение после ответа не открывается, отказ 503 новую попытку не планирует (два теста WS-09). Кадр не JSON больше не бросает исключение в обработчике `message` (провайдер WS-02). Для `Promise.withResolvers` в тестах в `tsconfig.json` добавлен `es2024.promise`.
- WS-сервер: Redis `connectionTimeout`/`dataTimeout` 2 с и `retries => 1`, Postgres `connect_timeout`, `tcp_user_timeout` и `statement_timeout` 2 с в DSN (`ws/config/main.php`). `TcpConnection::$defaultMaxPackageSize = 8192`: кадр больше 8 КБ Workerman закрывает до буферизации (без кода 4400), 4 КБ протокола по-прежнему проверяет `MessageHandler`. `WS_ALLOWED_ORIGINS` режется с `trim`, `runtimePath` ws-процесса — во временном каталоге, а не в `ws/runtime`.
- Токен, выданный до logout или смены пароля, живёт до 60 секунд: пункт и сценарий REG-WS-11 внесены в тикет 07 и `regression.md`.

Оставлено: `SET NX` без проверки результата в `WsTokenService` (при 256 битах коллизия нереальна, править вместе с интерфейсом Redis незачем); ограничение числа неавторизованных соединений (`limit_conn` в nginx) — отдельное решение по эксплуатации; запахи из ревью (двойной `json_decode` в `MessageHandler`, Data Clump в `ConnectionRegistry::authenticate`, 0 как «нет таймера», `createWsClient` как Middle Man, повтор проверки «объект с ключом» в `api.js` и `ws-client.js`) — на рефакторинг, поведение не меняют.
